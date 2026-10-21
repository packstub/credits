<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Packstub\Credits\Exceptions\InsufficientCredits;
use Packstub\Credits\Models\Wallet;
use Packstub\Credits\Tests\Fixtures\Models\Team;

/**
 * Real processes racing for one wallet, on a database file they share. The
 * in-memory database of the other tests cannot show this: every spend there
 * runs one after another in a single process.
 */
it('never spends the same credits twice when processes race', function () {
    $path = sys_get_temp_dir().'/packstub-credits-race-'.getmypid().'.sqlite';
    @unlink($path);
    touch($path);

    // IMMEDIATE takes the write lock when the transaction begins, as SELECT … FOR UPDATE does on Postgres and
    // MySQL; SQLite's default (DEFERRED) would let two spends read the same balance and fail one at write time.
    config(['database.connections.race' => [
        'driver' => 'sqlite',
        'database' => $path,
        'prefix' => '',
        'busy_timeout' => 10_000,
        'transaction_mode' => 'IMMEDIATE',
        'foreign_key_constraints' => false,
    ], 'database.default' => 'race']);

    Artisan::call('migrate', ['--database' => 'race', '--path' => realpath(__DIR__.'/../Fixtures/migrations'), '--realpath' => true]);
    Artisan::call('migrate', ['--database' => 'race', '--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true]);

    $team = Team::query()->create(['name' => 'Acme']);
    $team->credits()->grant(100);
    DB::disconnect('race');

    $children = [];

    foreach (range(1, 20) as $i) {
        $pid = pcntl_fork();

        if ($pid === 0) {
            DB::purge('race');

            try {
                Team::query()->findOrFail($team->id)->credits()->spend(10, key: "race:{$i}");
                $code = 0;
            } catch (InsufficientCredits) {
                $code = 1;
            } catch (Throwable) {
                $code = 2;
            }

            // Leave without PHPUnit's shutdown handlers: the parent reads the exit code.
            posix_kill(posix_getpid(), $code === 0 ? SIGUSR1 : ($code === 1 ? SIGUSR2 : SIGTERM));
        }

        $children[] = $pid;
    }

    $outcomes = collect($children)->map(function (int $pid) {
        pcntl_waitpid($pid, $status);

        return match (pcntl_wtermsig($status)) {
            SIGUSR1 => 'spent',
            SIGUSR2 => 'refused',
            default => 'error',
        };
    })->countBy()->sortKeys()->all();

    DB::purge('race');
    $wallet = Wallet::query()->sole();

    expect($outcomes)->toBe(['refused' => 10, 'spent' => 10])
        ->and($wallet->balance)->toBe(0)
        ->and($wallet->transactions()->count())->toBe(11);

    $this->artisan('credits:audit')->assertSuccessful();

    @unlink($path);
})->skip(! function_exists('pcntl_fork'), 'needs pcntl');
