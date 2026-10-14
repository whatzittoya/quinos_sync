<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A purchase document copied from Quinos; written only by sync code. */
class PurchaseOrder extends Model
{
    use BelongsToClient;

    protected $guarded = [];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'delivery_date' => 'date:Y-m-d',
        'closed' => 'boolean',
        'quinos_created_at' => 'datetime',
        'quinos_updated_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class, 'purchase_order_id');
    }
}
