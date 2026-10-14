<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One reusable quinosbackend.com login session per client, so jobs
        // do not log in again for every request.
        Schema::create('quinos_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('cookies')->nullable(); // encrypted JSON, see model cast
            $table->string('inertia_version')->nullable();
            $table->timestamp('logged_in_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quinos_sessions');
    }
};
