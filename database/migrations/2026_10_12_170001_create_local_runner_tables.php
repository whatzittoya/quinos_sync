<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('local_sync_runs', function(Blueprint $t){$t->id();$t->uuid('run_uuid')->unique();$t->string('status',20)->default('waiting');$t->timestamp('started_at')->nullable();$t->timestamp('finished_at')->nullable();$t->text('error')->nullable();$t->timestamps();});
  Schema::create('local_sync_run_items', function(Blueprint $t){$t->id();$t->foreignId('local_sync_run_id')->constrained()->cascadeOnDelete();$t->unsignedBigInteger('tenant_id');$t->string('tenant_name');$t->string('package',30);$t->text('dates');$t->string('status',20)->default('waiting');$t->unsignedInteger('rows')->default(0);$t->timestamp('started_at')->nullable();$t->timestamp('finished_at')->nullable();$t->text('error')->nullable();$t->timestamps();$t->unique(['local_sync_run_id','tenant_id','package']);});
  Schema::create('local_settings', function(Blueprint $t){$t->id();$t->string('hosted_api_url');$t->text('runner_token')->nullable();$t->timestamps();});
  Schema::create('scheduler_settings', function(Blueprint $t){$t->id();$t->boolean('enabled')->default(false);$t->string('run_time',5)->default('02:30');$t->string('task_name')->default('Quinos Local Sync');$t->string('last_action')->nullable();$t->text('last_error')->nullable();$t->timestamps();});
 }
 public function down(): void {Schema::dropIfExists('scheduler_settings');Schema::dropIfExists('local_settings');Schema::dropIfExists('local_sync_run_items');Schema::dropIfExists('local_sync_runs');}
};
