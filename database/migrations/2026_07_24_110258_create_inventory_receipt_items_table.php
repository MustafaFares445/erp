<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_receipt_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->decimal('purchase_cost', 15, 2)->nullable();
            $table->string('currency_code', 3)->default('USD');
            $table->date('expires_at')->nullable()->index();
            $table->string('lot_number', 100)->nullable();
            $table->timestamps();

            $table->index(['inventory_receipt_id', 'product_variant_id'], 'receipt_item_variant_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_receipt_items');
    }
};
