<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Model;

/** Reference data copied from Quinos by the sync; written only by sync code. */
class SalesType extends Model
{
    use BelongsToClient;

    protected $guarded = [];

    protected $casts = ['active' => 'boolean',];
}
