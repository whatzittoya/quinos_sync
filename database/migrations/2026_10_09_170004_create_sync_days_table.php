<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per client, data package and day: this is what the sync calendar shows.
        Schema::create('sync_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('package', 30);
            $table->date('date');
            $table->string('status', 20)->default('pending'); // pending | running | done | failed
            $table->timestamp('synced_at')->nullable();
            $table->unsignedInteger('rows')->default(0);
            // True when the day was synced before it ended, so more may still come.
            $table->boolean('partial')->default(false);
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'package', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_days');
    }
};
