<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\LocalSetting;
use App\Models\LocalSyncRun;
use App\Models\LocalSchedule;
use App\Runner\LocalSyncService;
use App\Runner\RunnerApi;
use App\Services\WindowsTaskScheduler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Throwable;
class LocalRunnerController extends Controller {
 public function settings(){return ['settings'=>LocalSetting::current()->only(['hosted_api_url']),'schedules'=>['master'=>LocalSchedule::forKind('master'),'reports'=>LocalSchedule::forKind('reports')]];}
 public function saveSettings(Request $r){$d=$r->validate(['hosted_api_url'=>'required|url','runner_token'=>'nullable|string|min:32']);$s=LocalSetting::current();$s->hosted_api_url=rtrim($d['hosted_api_url'],'/');if(array_key_exists('runner_token',$d)&&filled($d['runner_token']))$s->runner_token=$d['runner_token'];$s->save();return ['ok'=>true];}
 public function tenants(RunnerApi $api){return ['data'=>$api->tenants()];}
 public function test(RunnerApi $api){$api->test();return ['ok'=>true];}
 public function runs(){return ['data'=>LocalSyncRun::with('items')->latest()->limit(30)->get()];}
 /** A full run takes minutes; the web server's default 30-120 second limit would cut it off half way. */
 private function longRun():void{@set_time_limit(0);ignore_user_abort(true);}
 public function retry(int $id,LocalSyncService $s){$this->longRun();return response()->json($s->retry($id),202);}
 public function start(Request $r,LocalSyncService $s){$this->longRun();$d=$r->validate(['tenant'=>'nullable|integer','master_only'=>'sometimes|boolean','reports_only'=>'sometimes|boolean']);$run=$s->run($d['tenant']??null,(bool)($d['master_only']??false),null,(bool)($d['reports_only']??false));return response()->json($run->load('items'),202);}
 public function scheduler(Request $r,WindowsTaskScheduler $w){if(is_string($r->input('time'))&&preg_match('/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/',$r->input('time')))$r->merge(['time'=>substr($r->input('time'),0,5)]);$d=$r->validate(['kind'=>'required|in:master,reports','action'=>'required|in:create,enable,disable,delete,query','time'=>'nullable|date_format:H:i']);$schedule=LocalSchedule::forKind($d['kind']);try{$result=match($d['action']){'create'=>$w->create($d['kind'],$d['time']??$schedule->run_time),'enable'=>$w->enable($d['kind']),'disable'=>$w->disable($d['kind']),'delete'=>$w->delete($d['kind']),'query'=>$w->query($d['kind'])};return ['ok'=>true,'result'=>$result];}catch(Throwable $e){$schedule->update(['last_error'=>mb_substr($e->getMessage(),0,480)]);return response()->json(['message'=>$e->getMessage()],422);}}
}
