<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A POS transaction copied from Quinos; written only by sync code. */
class SalesTransaction extends Model
{
    use BelongsToClient;

    protected $guarded = [];

    protected $casts = ['date' => 'date:Y-m-d'];

    public function lines(): HasMany
    {
        return $this->hasMany(SalesLine::class);
    }
}
