<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('local_schedules', function (Blueprint $t) {
            $t->id(); $t->string('kind', 20)->unique(); $t->boolean('enabled')->default(false);
            $t->string('run_time', 5)->default('02:30'); $t->string('task_name');
            $t->string('last_action')->nullable(); $t->text('last_error')->nullable(); $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('local_schedules'); }
};
