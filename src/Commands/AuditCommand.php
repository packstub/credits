<?php

namespace Packstub\Credits\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Packstub\Credits\Enums\HoldStatus;
use Packstub\Credits\Models\Wallet;

/**
 * Prove the books balance. Every cached number is recomputed from the rows
 * under it: the wallet from its lots, its holds and its ledger; each lot
 * from the allocations that filled and drained it, and within its own
 * amount; each transaction from its own allocations. Read-only; exits non-zero on any drift, so it can
 * run in CI against a copy of production or nightly with an alert.
 */
class AuditCommand extends Command
{
    protected $signature = 'credits:audit {--wallet=* : Only these wallet ids}';

    protected $description = 'Check that every credit wallet agrees with its lots, holds and ledger';

    public function handle(): int
    {
        $problems = [];
        $checked = 0;

        $wallets = Wallet::query()->when($this->option('wallet'), fn ($query, $ids) => $query->whereKey($ids));

        $wallets->chunkById(500, function ($chunk) use (&$problems, &$checked) {
            $ids = $chunk->modelKeys();
            $lots = $this->sums('credit_grants', 'remaining', $ids);
            $reserved = $this->sums('credit_holds', 'amount', $ids, fn ($query) => $query->where('status', HoldStatus::Pending->value));
            $ledger = $this->sums('credit_transactions', 'amount', $ids);
            $heldLedger = $this->sums('credit_transactions', 'held_change', $ids);
            $last = DB::table('credit_transactions')
                ->whereIn('id', DB::table('credit_transactions')->selectRaw('max(id)')->whereIn('wallet_id', $ids)->groupBy('wallet_id'))
                ->get(['wallet_id', 'balance_after', 'held_after'])
                ->keyBy('wallet_id');

            foreach ($chunk as $wallet) {
                $checked++;
                $id = $wallet->id;
                $tail = $last[$id] ?? null;

                $checks = [
                    'balance = unspent credits of its lots' => [$wallet->balance, $lots[$id] ?? 0],
                    'held = pending holds' => [$wallet->held, $reserved[$id] ?? 0],
                    'balance = sum of the ledger' => [$wallet->balance, $ledger[$id] ?? 0],
                    'held = sum of the ledger\'s held changes' => [$wallet->held, $heldLedger[$id] ?? 0],
                    'balance = the last line\'s balance_after' => [$wallet->balance, (int) ($tail->balance_after ?? 0)],
                    'held = the last line\'s held_after' => [$wallet->held, (int) ($tail->held_after ?? 0)],
                ];

                foreach ($checks as $check => [$cached, $computed]) {
                    if ((int) $cached !== (int) $computed) {
                        $problems[] = [$id, $check, $cached, $computed];
                    }
                }

                if ($wallet->held > $wallet->balance) {
                    $problems[] = [$id, 'held <= balance', $wallet->held, $wallet->balance];
                }

                if ($wallet->held < 0) {
                    $problems[] = [$id, 'held >= 0', $wallet->held, 0];
                }
            }

            foreach ($this->lotsOutOfBounds($ids) as $row) {
                $problems[] = [$row->wallet_id, "lot {$row->id}: 0 <= remaining <= amount", $row->remaining, $row->amount];
            }

            foreach ($this->lotDrift($ids) as $row) {
                $problems[] = [$row->wallet_id, "lot {$row->id}: remaining = its allocations", $row->remaining, $row->allocated];
            }

            foreach ($this->transactionDrift($ids) as $row) {
                $problems[] = [$row->wallet_id, "transaction {$row->id}: amount = its allocations", $row->amount, $row->allocated];
            }
        });

        if ($problems === []) {
            $this->components->info(sprintf('%d %s balanced.', $checked, str('wallet')->plural($checked)));

            return self::SUCCESS;
        }

        $this->components->error(sprintf('%d %s found.', count($problems), str('problem')->plural(count($problems))));
        $this->table(['Wallet', 'Check', 'Cached', 'Computed'], $problems);

        return self::FAILURE;
    }

    /** @return array<int, int> wallet id => sum */
    private function sums(string $table, string $column, array $ids, ?callable $scope = null): array
    {
        return DB::table($table)
            ->whereIn('wallet_id', $ids)
            ->when($scope, $scope)
            ->groupBy('wallet_id')
            ->selectRaw("wallet_id, sum({$column}) as total")
            ->pluck('total', 'wallet_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    private function lotDrift(array $ids): iterable
    {
        return DB::table('credit_grants as g')
            ->leftJoin('credit_allocations as a', 'a.grant_id', '=', 'g.id')
            ->whereIn('g.wallet_id', $ids)
            ->groupBy('g.id', 'g.wallet_id', 'g.remaining')
            ->havingRaw('g.remaining <> coalesce(sum(a.amount), 0)')
            ->selectRaw('g.id, g.wallet_id, g.remaining, coalesce(sum(a.amount), 0) as allocated')
            ->get();
    }

    private function lotsOutOfBounds(array $ids): iterable
    {
        return DB::table('credit_grants')
            ->whereIn('wallet_id', $ids)
            ->where(fn ($query) => $query->where('remaining', '<', 0)->orWhereColumn('remaining', '>', 'amount'))
            ->get(['id', 'wallet_id', 'remaining', 'amount']);
    }

    private function transactionDrift(array $ids): iterable
    {
        return DB::table('credit_transactions as t')
            ->leftJoin('credit_allocations as a', 'a.transaction_id', '=', 't.id')
            ->whereIn('t.wallet_id', $ids)
            ->groupBy('t.id', 't.wallet_id', 't.amount')
            ->havingRaw('t.amount <> coalesce(sum(a.amount), 0)')
            ->selectRaw('t.id, t.wallet_id, t.amount, coalesce(sum(a.amount), 0) as allocated')
            ->get();
    }
}
