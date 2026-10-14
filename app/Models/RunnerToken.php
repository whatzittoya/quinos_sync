<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class RunnerToken extends Model
{
    protected $guarded = [];
    protected $hidden = ['token_hash'];
    protected $casts = ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
}
