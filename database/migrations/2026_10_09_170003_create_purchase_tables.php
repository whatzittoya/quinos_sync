<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $this->header($table);
            $table->string('pr_no')->nullable();
            $table->date('delivery_date')->nullable();
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $this->line($table, 'purchase_order_id', 'purchase_orders');
            $table->decimal('received', 18, 4)->default(0);
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $this->header($table);
            $table->string('po_no')->nullable();
            $table->unsignedBigInteger('purchase_order_quinos_id')->nullable()->index();
            $table->unsignedBigInteger('purchase_invoice_quinos_id')->nullable()->index();
            $table->boolean('by_system')->default(false);
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $this->line($table, 'goods_receipt_id', 'goods_receipts');
        });

        Schema::create('purchase_invoices', function (Blueprint $table) {
            $this->header($table);
            $table->boolean('direct')->default(false);
            $table->text('receipt_ids')->nullable(); // Quinos ids of the goods receipts it covers
            $table->decimal('balance', 18, 2)->default(0);
        });
    }

    public function down(): void
    {
        foreach (['purchase_invoices', 'goods_receipt_lines', 'goods_receipts', 'purchase_order_lines', 'purchase_orders'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    /** Columns every purchase document has. */
    private function header(Blueprint $table): void
    {
        $table->id();
        $table->foreignId('client_id')->constrained()->cascadeOnDelete();
        $table->unsignedBigInteger('quinos_id');
        $table->string('no');
        $table->string('ref_no')->nullable();
        $table->date('date');
        $table->unsignedBigInteger('contact_quinos_id')->nullable();
        $table->string('contact_name')->nullable();
        $table->unsignedBigInteger('warehouse_quinos_id')->nullable();
        $table->string('warehouse_name')->nullable();
        $table->string('status')->nullable();
        $table->boolean('closed')->default(false);
        $table->decimal('subtotal', 18, 2)->default(0);
        $table->decimal('tax_amount', 18, 2)->default(0);
        $table->decimal('total', 18, 2)->default(0);
        $table->timestamp('quinos_created_at')->nullable();
        $table->timestamp('quinos_updated_at')->nullable();
        $table->timestamp('synced_at')->nullable();
        $table->timestamps();

        $table->unique(['client_id', 'quinos_id']);
        $table->index(['client_id', 'date']);
    }

    /** Columns every document line has. */
    private function line(Blueprint $table, string $parentColumn, string $parentTable): void
    {
        $table->id();
        $table->foreignId('client_id')->constrained()->cascadeOnDelete();
        $table->foreignId($parentColumn)->constrained($parentTable)->cascadeOnDelete();
        $table->unsignedBigInteger('quinos_id')->nullable();
        $table->unsignedBigInteger('item_quinos_id')->nullable();
        $table->string('code')->nullable();
        $table->string('name');
        $table->string('category')->nullable();
        $table->string('uom')->nullable();
        $table->decimal('quantity', 18, 4)->default(0);
        $table->decimal('unit_price', 18, 2)->default(0);
        $table->decimal('subtotal', 18, 2)->default(0);
        $table->string('tax_code')->nullable();
        $table->decimal('tax_rate', 8, 4)->default(0);
        $table->decimal('tax_amount', 18, 2)->default(0);
        $table->timestamps();

        $table->index($parentColumn);
    }
};
