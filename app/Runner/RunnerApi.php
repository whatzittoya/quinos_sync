<?php
namespace App\Runner;
use App\Models\LocalSetting;
use Illuminate\Support\Facades\Http;
use RuntimeException;
class RunnerApi {
 private function client(){ $s=LocalSetting::current(); if(blank($s->hosted_api_url)||blank($s->runner_token)) throw new RuntimeException('Configure the hosted API URL and runner token first.'); return Http::baseUrl(rtrim($s->hosted_api_url,'/'))->withToken($s->runner_token)->acceptJson()->timeout(config('runner.timeout')); }
 public function tenants(): array { $r=$this->client()->get('/api/runner/v1/tenants'); if(!$r->successful()) throw new RuntimeException('Hosted tenant configuration request failed (HTTP '.$r->status().').'); return $r->json('tenants') ?? []; }
 public function syncDays(int $tenant): array { $r=$this->client()->get('/api/runner/v1/tenants/'.$tenant.'/sync-days'); if($r->status()===404) throw new RuntimeException('The hosted app does not support pulling sync status yet. Deploy its latest version first.'); if(!$r->successful()) throw new RuntimeException('Hosted sync status request failed (HTTP '.$r->status().').'); return $r->json('days') ?? []; }
 public function begin(string $id,int $tenant,string $package,array $dates,string $source,?string $cutoff,array $chunks): array { $r=$this->client()->post('/api/runner/v1/imports',['id'=>$id,'client_id'=>$tenant,'package'=>$package,'dates'=>$dates,'source_synced_at'=>$source,'purchase_cutoff'=>$cutoff,'chunk_count'=>count($chunks)]); if(!$r->successful()) throw new RuntimeException($r->json('message') ?? 'Hosted import could not start.'); return $r->json(); }
 public function chunk(string $id,int $sequence,array $tables): void { $r=$this->client()->put('/api/runner/v1/imports/'.$id.'/chunks/'.$sequence,['tables'=>$tables]); if(!$r->successful()) throw new RuntimeException($r->json('message') ?? 'Hosted import chunk failed.'); }
 public function commit(string $id): array { $r=$this->client()->post('/api/runner/v1/imports/'.$id.'/commit'); if(!$r->successful()) throw new RuntimeException($r->json('message') ?? 'Hosted import commit failed.'); return $r->json(); }
 public function test(): void { $this->tenants(); }
}
