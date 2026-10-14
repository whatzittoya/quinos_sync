<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reference data copied from Quinos. quinos_id is the id on their side.
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('quinos_id');
            $table->string('name');
            $table->timestamps();

            $table->unique(['client_id', 'quinos_id']);
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('quinos_id');
            $table->unsignedBigInteger('department_quinos_id')->nullable();
            $table->string('name');
            $table->timestamps();

            $table->unique(['client_id', 'quinos_id']);
        });

        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('quinos_id');
            $table->string('code')->nullable();
            $table->string('name');
            $table->unsignedBigInteger('category_quinos_id')->nullable();
            $table->unsignedBigInteger('department_quinos_id')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['client_id', 'quinos_id']);
            $table->index(['client_id', 'code']);
        });

        Schema::create('sales_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('quinos_id');
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['client_id', 'quinos_id']);
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('quinos_id');
            $table->string('name');
            $table->boolean('supplier')->default(false);
            $table->boolean('customer')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['client_id', 'quinos_id']);
        });
    }

    public function down(): void
    {
        foreach (['contacts', 'sales_types', 'items', 'categories', 'departments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
