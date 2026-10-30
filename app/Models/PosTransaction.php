<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Model;

/** A bill in the mobile app's format; written only by sync code (locally) or runner imports (hosted). */
class PosTransaction extends Model
{
    use BelongsToClient;

    protected $guarded = [];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'transaction' => 'array',
        'transaction_lines' => 'array',
    ];
}
