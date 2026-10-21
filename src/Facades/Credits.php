<?php

namespace Packstub\Credits\Facades;

use Illuminate\Support\Facades\Facade;
use Packstub\Credits\CreditsManager;

/**
 * @method static \Packstub\Credits\Models\Wallet wallet(\Illuminate\Database\Eloquent\Model $holder, string $name = 'default')
 * @method static void allowancesUsing(\Closure|null $resolver)
 * @method static array allowancesFor(\Packstub\Credits\Models\Wallet $wallet)
 * @method static int expire()
 *
 * @see CreditsManager
 */
class Credits extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CreditsManager::class;
    }
}
