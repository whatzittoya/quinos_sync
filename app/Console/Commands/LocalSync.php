<?php
namespace App\Console\Commands;
use App\Runner\LocalSyncService;
use Illuminate\Console\Command;
class LocalSync extends Command { protected $signature='local:sync {--tenant=} {--master-only} {--reports-only} {--retry=} {--scheduled}'; protected $description='Sync all configured Quinos tenants locally and upload acknowledged batches'; public function handle(LocalSyncService $service):int{$run=$this->option('retry')?$service->retry((int)$this->option('retry')):$service->run($this->option('tenant')?(int)$this->option('tenant'):null,(bool)$this->option('master-only'),null,(bool)$this->option('reports-only'),$this->option('scheduled')?'scheduled':'manual');$this->info('Run '.$run->run_uuid.' '.$run->status);return $run->status==='success'?self::SUCCESS:self::FAILURE;} }
