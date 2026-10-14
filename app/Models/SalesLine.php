<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Model;

class SalesLine extends Model
{
    use BelongsToClient;

    public $timestamps = false;

    protected $guarded = [];
}
