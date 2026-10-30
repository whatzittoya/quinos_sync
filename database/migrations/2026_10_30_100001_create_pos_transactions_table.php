<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bills in the mobile app's format (old quinoscloud getTransactionByDate), built by the local runner
        // from the POS reports and uploaded as the `pos` package. One set per synced day.
        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('quinos_id');
            $table->date('date'); // the day it was synced for
            $table->string('invoice_no')->nullable();
            $table->string('store_code')->nullable(); // upper-case outlet code, e.g. ZALI.ULUWATU
            $table->dateTime('close_time')->nullable();
            // Text, not JSON: MySQL's JSON type reorders keys, and the app gets them in the old order.
            $table->longText('transaction');
            $table->longText('transaction_lines');
            $table->timestamps();

            $table->unique(['client_id', 'quinos_id']);
            $table->index(['client_id', 'date', 'store_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_transactions');
    }
};
