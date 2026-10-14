<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // POS transactions copied from Quinos' transaction detail report, one set per synced day.
        Schema::create('sales_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('quinos_id');
            $table->date('date'); // the day it was synced for
            $table->string('invoice_no')->nullable();
            $table->string('outlet_name')->nullable();
            $table->string('sales_type', 50)->nullable();
            $table->unsignedInteger('pax')->default(0);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount', 18, 2)->default(0);
            $table->decimal('serv_chg', 18, 2)->default(0);
            $table->decimal('tax', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            // Local (outlet) time exactly as Quinos shows it, no timezone conversion.
            $table->dateTime('open_time')->nullable();
            $table->dateTime('close_time')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'quinos_id']);
            $table->index(['client_id', 'date']);
        });

        // Sold items and discounts; payments are not needed for reports.
        Schema::create('sales_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_transaction_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // ITEM | DISCOUNT
            $table->string('code')->nullable();
            $table->string('name');
            $table->decimal('quantity', 18, 4)->default(0);
            $table->decimal('unit_price', 18, 2)->default(0);
            $table->decimal('amount', 18, 2)->default(0); // discounts: the amount taken off

            $table->index('sales_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_lines');
        Schema::dropIfExists('sales_transactions');
    }
};
