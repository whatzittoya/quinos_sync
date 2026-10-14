<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A purchase document copied from Quinos; written only by sync code. */
class PurchaseInvoice extends Model
{
    use BelongsToClient;

    protected $guarded = [];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'direct' => 'boolean', 'receipt_ids' => 'array',
        'closed' => 'boolean',
        'quinos_created_at' => 'datetime',
        'quinos_updated_at' => 'datetime',
        'synced_at' => 'datetime',
    ];
}
