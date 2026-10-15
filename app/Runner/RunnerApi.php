<?php
namespace App\Runner;
use App\Models\LocalSetting;
use Illuminate\Support\Facades\Http;
use RuntimeException;
class RunnerApi {
 private function client(){ $s=LocalSetting::current(); if(blank($s->hosted_api_url)||blank($s->runner_token)) throw new RuntimeException('Configure the hosted API URL and runner token first.'); return Http::baseUrl(rtrim($s->hosted_api_url,'/'))->withToken($s->runner_token)->acceptJson()->connectTimeout(config('runner.connect_timeout'))->timeout(config('runner.timeout'))
  /* A dropped connection must not fail minutes of work: retry connection errors (begin, chunk and commit are safe to repeat). Only connection errors are retried, never an answer from the server. */
  ->retry(config('runner.retries'),config('runner.retry_delay_ms'),fn($e)=>$e instanceof \Illuminate\Http\Client\ConnectionException,false); }
 public function tenants(): array { $r=$this->client()->get('/api/runner/v1/tenants'); if(!$r->successful()) throw new RuntimeException('Hosted tenant configuration request failed (HTTP '.$r->status().').'); return $r->json('tenants') ?? []; }
 public function syncDays(int $tenant): array { try{ $r=$this->client()->retry(1)->connectTimeout(4)->timeout(8)->get('/api/runner/v1/tenants/'.$tenant.'/sync-days'); }catch(\Illuminate\Http\Client\ConnectionException){ /* a page open must not hang or fail because hosted is briefly unreachable */ throw new RuntimeException('Could not reach the hosted app. Check this PC\'s internet connection.'); } if($r->status()===404) throw new RuntimeException('The hosted app does not support pulling sync status yet. Deploy its latest version first.'); if(!$r->successful()) throw new RuntimeException('Hosted sync status request failed (HTTP '.$r->status().').'); return $r->json('days') ?? []; }
 public function begin(string $id,int $tenant,string $package,array $dates,string $source,?string $cutoff,array $chunks): array { $r=$this->client()->post('/api/runner/v1/imports',['id'=>$id,'client_id'=>$tenant,'package'=>$package,'dates'=>$dates,'source_synced_at'=>$source,'purchase_cutoff'=>$cutoff,'chunk_count'=>count($chunks)]); if(!$r->successful()) throw new RuntimeException($r->json('message') ?? 'Hosted import could not start.'); return $r->json(); }
 public function chunk(string $id,int $sequence,array $tables): void { $r=$this->client()->put('/api/runner/v1/imports/'.$id.'/chunks/'.$sequence,['tables'=>$tables]); if(!$r->successful()) throw new RuntimeException($r->json('message') ?? 'Hosted import chunk failed.'); }
 public function commit(string $id): array { $r=$this->client()->post('/api/runner/v1/imports/'.$id.'/commit'); if(!$r->successful()) throw new RuntimeException($r->json('message') ?? 'Hosted import commit failed.'); return $r->json(); }
 public function test(): void { $this->tenants(); }
}
