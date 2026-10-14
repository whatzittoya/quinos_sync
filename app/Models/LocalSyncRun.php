<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class LocalSyncRun extends Model { protected $guarded=[]; protected $casts=['run_uuid'=>'string','started_at'=>'datetime','finished_at'=>'datetime']; public function items(){return $this->hasMany(LocalSyncRunItem::class);} }
