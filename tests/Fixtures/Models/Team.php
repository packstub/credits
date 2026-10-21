<?php

namespace Packstub\Credits\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Packstub\Credits\Concerns\HasCredits;

class Team extends Model
{
    use HasCredits;

    protected $guarded = [];
}
