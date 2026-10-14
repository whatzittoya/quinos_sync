<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Model;

/** Reference data copied from Quinos by the sync; written only by sync code. */
class Contact extends Model
{
    use BelongsToClient;

    protected $guarded = [];

    protected $casts = ['supplier' => 'boolean', 'customer' => 'boolean', 'active' => 'boolean',];
}
