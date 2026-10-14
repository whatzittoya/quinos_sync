<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class LocalSyncRunItem extends Model { protected $guarded=[]; protected $casts=['dates'=>'array','started_at'=>'datetime','finished_at'=>'datetime']; public function run(){return $this->belongsTo(LocalSyncRun::class,'local_sync_run_id');} }
