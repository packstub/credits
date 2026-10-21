<?php

namespace Packstub\Credits\Commands;

use Illuminate\Console\Command;
use Packstub\Credits\Facades\Credits;

/**
 * Wallets catch up whenever they are used; schedule this (hourly is plenty) so lots and holds of idle wallets
 * expire on time too and their events fire.
 */
class ExpireCommand extends Command
{
    protected $signature = 'credits:expire';

    protected $description = 'Expire due credit lots and release stale holds on every wallet';

    public function handle(): int
    {
        $this->components->info(sprintf('%d %s caught up.', $count = Credits::expire(), str('wallet')->plural($count)));

        return self::SUCCESS;
    }
}
