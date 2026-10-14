<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Login used to read the client's data from quinosbackend.com.
            $table->string('quinos_email')->nullable();
            $table->text('quinos_password')->nullable(); // encrypted via model cast
            $table->string('quinos_company_id')->nullable();
            $table->string('timezone')->default('Asia/Makassar');
            // Which transaction timestamp decides the shift: open_time | close_time.
            $table->string('shift_assign_by')->default('open_time');
            $table->boolean('active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('client_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('starts_at', 5); // HH:MM, inclusive
            $table->string('ends_at', 5);   // HH:MM, exclusive, 24:00 allowed
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['client_id', 'position']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('client')->after('password');
            $table->foreignId('client_id')->nullable()->after('role')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn('role');
        });

        Schema::dropIfExists('client_shifts');
        Schema::dropIfExists('clients');
    }
};
