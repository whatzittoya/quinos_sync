<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SchedulerSetting extends Model { protected $guarded=[]; protected $casts=['enabled'=>'boolean']; public static function current(){return static::first() ?? static::create(['run_time'=>'02:30','task_name'=>config('runner.task_name')]);} }
