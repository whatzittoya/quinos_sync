<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class RunnerImport extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = ['dates' => 'array', 'chunk_count' => 'integer', 'counts' => 'array', 'source_synced_at' => 'datetime', 'purchase_cutoff' => 'datetime', 'completed_at' => 'datetime'];
}
