<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase order lines (data-model.md §3, ERD extension E-2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_product_reference_id')->nullable()->constrained()->nullOnDelete();
            $table->string('supplier_item_number', 100)->nullable();
            $table->decimal('quantity_ordered', 15, 3);
            $table->decimal('quantity_received', 15, 3)->default(0);
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->decimal('last_received_unit_cost', 15, 2)->nullable();
            $table->decimal('line_total', 15, 2)->default(0);
            $table->date('expected_at')->nullable();
            $table->timestamps();

            // Makes FR-014's duplicate rejection a database guarantee rather
            // than a validation-only rule, and makes receipt attribution
            // unambiguous: one line per variant-and-unit, so an incoming
            // received quantity has exactly one line to land on.
            $table->unique(['purchase_order_id', 'product_variant_id', 'unit_id'], 'purchase_order_line_variant_unit_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
    }
};
