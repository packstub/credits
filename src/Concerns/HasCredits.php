<?php

namespace Packstub\Credits\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Packstub\Credits\Facades\Credits;
use Packstub\Credits\Models\Wallet;

/** For the model that holds credits: `$team->credits()->spend(5)`, `$user->credits('sms')->available()`. */
trait HasCredits
{
    public function credits(string $name = 'default'): Wallet
    {
        return Credits::wallet($this, $name);
    }

    public function creditWallets(): MorphMany
    {
        return $this->morphMany(Wallet::class, 'holder');
    }
}
