<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class LocalSetting extends Model { protected $guarded=[]; protected $casts=['runner_token'=>'encrypted']; public static function current(){return static::first() ?? static::create(['hosted_api_url'=>config('runner.api_url'),'runner_token'=>config('runner.token')]);} }
