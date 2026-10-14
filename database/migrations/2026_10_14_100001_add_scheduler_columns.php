<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('clients', fn (Blueprint $t) => $t->boolean('scheduled')->default(true)->after('active'));
        Schema::table('local_sync_runs', fn (Blueprint $t) => $t->string('trigger', 20)->default('manual')->after('status'));
    }
    public function down(): void {
        Schema::table('local_sync_runs', fn (Blueprint $t) => $t->dropColumn('trigger'));
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('scheduled'));
    }
};
