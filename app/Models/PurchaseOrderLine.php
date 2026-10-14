<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderLine extends Model
{
    use BelongsToClient;

    protected $guarded = [];
}
