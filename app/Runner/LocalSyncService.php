<?php
namespace App\Runner;
use App\Models\Client;
use App\Models\LocalSyncRun;
use App\Models\LocalSyncRunItem;
use App\Sync\SyncRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;
class LocalSyncService {
 public function __construct(private RunnerApi $api) {}
 public function retry(int $runId): LocalSyncRun { $old=LocalSyncRun::with('items')->findOrFail($runId); $tenantIds=$old->items->where('status','failed')->pluck('tenant_id')->unique(); if($tenantIds->isEmpty()) return $this->run(); $last=null; foreach($tenantIds as $id) $last=$this->run((int)$id,false); return $last ?? $this->run(); }
 public function run(?int $onlyTenant=null,bool $masterOnly=false,?int $retryRun=null,bool $reportsOnly=false,string $trigger='manual'): LocalSyncRun {
  $this->closeAbandoned();
  $run=LocalSyncRun::create(['run_uuid'=>(string) Str::uuid(),'status'=>'running','trigger'=>$trigger,'started_at'=>now()]);
  try { $tenants=$this->api->tenants(); foreach($tenants as $config){if($onlyTenant!==null && (int)$config['id']!==$onlyTenant)continue; $this->tenant($run,$config,$masterOnly,$reportsOnly,$trigger==='scheduled');} $run->update(['status'=>$run->items()->where('status','failed')->exists()?'failed':'success','finished_at'=>now()]); }
  catch(Throwable $e){$run->update(['status'=>'failed','error'=>$this->safe($e),'finished_at'=>now()]);}
  return $run;
 }
 /** Create or refresh the local copy of a hosted tenant. The local `scheduled` choice is never overwritten. */
 public function upsertClient(array $cfg): Client {
  /* `id` is not mass-assignable, so updateOrCreate(['id'=>..]) would ignore it and number clients locally; the id must stay the hosted one. */
  $client=Client::find((int)$cfg['id']) ?? (new Client)->forceFill(['id'=>(int)$cfg['id']]); $client->fill(['name'=>$cfg['name'],'quinos_email'=>$cfg['quinos_email'],'quinos_password'=>$cfg['quinos_password'],'quinos_company_id'=>$cfg['quinos_company_id']??null,'timezone'=>$cfg['timezone'],'shift_assign_by'=>$cfg['shift_assign_by'],'active'=>true])->save();
  $client->shifts()->delete(); foreach($cfg['shifts']??[] as $s)$client->shifts()->create($s);
  return $client;
 }
 /** Sync one package of one client locally for the given dates, then upload it. Returns the uploaded row count. */
 public function push(Client $client,string $package,array $dates):int { $source=now(); $payload=$this->payload($client,$package,$dates); return $this->upload($client->id,$package,$dates,$source,$payload); }
 private function tenant(LocalSyncRun $run,array $cfg,bool $masterOnly,bool $reportsOnly,bool $scheduledOnly): void {
  $client=$this->upsertClient($cfg);
  if($scheduledOnly && $client->scheduled===false) return;
  $today=CarbonImmutable::now($client->timezone);
  $packages=$masterOnly?['master']:($reportsOnly?['purchase','sales']:['master','purchase','sales']); foreach($packages as $package){$dates=$package==='master'?[]:$this->reportDates($client,$package,$today); $item=LocalSyncRunItem::create(['local_sync_run_id'=>$run->id,'tenant_id'=>$client->id,'tenant_name'=>$client->name,'package'=>$package,'dates'=>$dates,'status'=>'running','started_at'=>now()]); try{$runner=new SyncRunner; if($package!=='master')$this->queueDays($client,$package,$dates); if($package==='master')$runner->run($client,[],true); else $runner->run($client,$dates,true,null,[$package]); $this->assertDone($client,$package,$dates); $count=$this->push($client,$package,$dates); $item->update(['status'=>'success','rows'=>$count,'finished_at'=>now()]);}catch(Throwable $e){$item->update(['status'=>'failed','error'=>$this->safe($e),'finished_at'=>now()]);}}
 }
 /** Yesterday and today, plus any earlier day in the catch-up window that is not fully synced yet (PC was off, a run failed, or the day was synced before it ended). */
 private function reportDates(Client $c,string $package,CarbonImmutable $today):array {
  $dates=[$today->subDay()->toDateString(),$today->toDateString()]; $window=min(60,max(0,(int)config('runner.catchup_days')));
  if($window>0){
   $done=\App\Models\SyncDay::where('client_id',$c->id)->where('package',$package)->whereBetween('date',[$today->subDays($window)->toDateString(),$today->subDays(2)->toDateString()])->where('status','done')->where('partial',false)->pluck('date')->map(fn($d)=>$d->toDateString())->all();
   for($d=$today->subDays($window);$d->lte($today->subDays(2));$d=$d->addDay()) if(!in_array($d->toDateString(),$done,true))$dates[]=$d->toDateString();
  }
  sort($dates); return array_values(array_unique($dates));
 }
 /** The runner only updates day records that exist (the Sync page creates them as pending first), so do the same here. */
 private function queueDays(Client $c,string $package,array $dates):void { foreach($dates as $d)\App\Models\SyncDay::updateOrCreate(['client_id'=>$c->id,'package'=>$package,'date'=>$d],['status'=>\App\Models\SyncDay::PENDING,'error'=>null]); }
 /** A run whose process died (window closed, PC switched off) never finishes its rows; stop showing them as running. */
 private function closeAbandoned():void {
  $old=now()->subHours(3);
  LocalSyncRunItem::where('status','running')->where('started_at','<',$old)->update(['status'=>'failed','error'=>'Interrupted: the process stopped before it finished.','finished_at'=>now()]);
  LocalSyncRun::where('status','running')->where('started_at','<',$old)->update(['status'=>'failed','finished_at'=>now()]);
 }
 private function assertDone(Client $c,string $package,array $dates):void{if($package==='master')return;foreach($dates as $d){$day=\App\Models\SyncDay::where('client_id',$c->id)->where('package',$package)->whereDate('date',$d)->first(); if($day?->status!=='done')throw new \RuntimeException(ucfirst($package).' sync did not complete for '.$d.($day?->error?': '.$day->error:'.'));}}
 private function payload(Client $c,string $package,array $dates):array { $out=[]; if($package==='master'){foreach(['departments'=>\App\Models\Department::class,'categories'=>\App\Models\Category::class,'items'=>\App\Models\Item::class,'sales_types'=>\App\Models\SalesType::class,'contacts'=>\App\Models\Contact::class] as $k=>$m)$out[$k]=$m::where('client_id',$c->id)->get()->map(fn($x)=>collect($x->toArray())->except(['id','client_id','created_at','updated_at'])->all())->all();return $out;} $map=$package==='purchase'?['purchase_orders'=>\App\Models\PurchaseOrder::class,'goods_receipts'=>\App\Models\GoodsReceipt::class,'purchase_invoices'=>\App\Models\PurchaseInvoice::class]:['sales_transactions'=>\App\Models\SalesTransaction::class]; foreach($map as $k=>$m){$q=$m::where('client_id',$c->id)->whereIn('date',$dates); if(in_array($k,['purchase_orders','goods_receipts','sales_transactions'],true)) $q->with('lines');$hasLines=in_array($k,['purchase_orders','goods_receipts','sales_transactions'],true);$out[$k]=$q->get()->map(function($x)use($hasLines){$a=collect($x->toArray())->except(['id','client_id','created_at','updated_at','lines'])->all();if($hasLines)$a['lines']=$x->lines->map(fn($l)=>collect($l->toArray())->except(['id','client_id','purchase_order_id','goods_receipt_id','sales_transaction_id','created_at','updated_at'])->all())->all();return $a;})->all();}return $out; }
 private function upload(int $tenant,string $package,array $dates,$source,array $payload):int { $chunks=[];$current=[];$n=0;foreach($payload as $table=>$rows){foreach(array_chunk($rows,config('runner.chunk_size')) as $part){$chunks[]=['tables'=>[$table=>$part]];}}if($chunks===[])$chunks[]=['tables'=>array_map(fn()=>[],$payload)]; /* a day with no documents still sends one empty chunk: hosted needs at least one, and committing it clears stale rows */ $id=(string)Str::uuid();$this->api->begin($id,$tenant,$package,$dates,$source->toIso8601String(),null,$chunks);foreach($chunks as $i=>$chunk)$this->api->chunk($id,$i,$chunk['tables']);$result=$this->api->commit($id);foreach($result['counts']??[] as $v)$n+=(int)$v;return $n; }
 private function safe(Throwable $e):string{return mb_substr($e->getMessage(),0,480);}
}
