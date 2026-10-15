<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('runner_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('runner_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('runner_token_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('package', 30);
            $table->text('dates');
            $table->timestamp('source_synced_at');
            $table->timestamp('purchase_cutoff')->nullable();
            $table->unsignedInteger('chunk_count');
            $table->string('status', 20)->default('waiting');
            $table->text('counts')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('runner_import_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('runner_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('digest', 64);
            $table->longText('payload');
            $table->unique(['runner_import_id', 'sequence']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('runner_import_chunks');
        Schema::dropIfExists('runner_imports');
        Schema::dropIfExists('runner_tokens');
    }
};
