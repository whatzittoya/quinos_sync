<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Model;

class ClientShift extends Model
{
    use BelongsToClient;

    protected $fillable = ['client_id', 'label', 'starts_at', 'ends_at', 'position'];
}
