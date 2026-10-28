<?php

namespace Packstub\Credits\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Packstub\Credits\Concerns\HasCredits;

/** A holder keyed by a UUID, for the suite that runs with `holder_key_type` = uuid. */
class Project extends Model
{
    use HasCredits;
    use HasUuids;

    protected $guarded = [];
}
