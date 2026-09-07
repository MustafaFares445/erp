<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_condition_changes', function (Blueprint $table): void {
            $table->id();
            $table->string('document_number', 30)->unique();
            $table->string('type', 40);
            $table->string('status', 20);
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_lot_id')->nullable()->constrained('inventory_lots')->nullOnDelete();
            $table->foreignId('serialized_inventory_unit_id')->nullable()->constrained('serialized_inventory_units')->nullOnDelete();
            $table->string('condition_from', 20);
            $table->string('condition_to', 20);
            $table->decimal('base_quantity', 18, 6);
            $table->string('disposition', 30)->nullable();
            $table->string('reason_category', 40);
            $table->text('reason');
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('inspected_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('inventory_movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->foreignId('supplier_return_id')->nullable()->constrained('inventory_returns')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['product_variant_id', 'warehouse_id', 'condition_from', 'status'],
                'inventory_condition_changes_stock_status_idx',
            );
            $table->index(['status', 'created_at'], 'inventory_condition_changes_ageing_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_condition_changes');
    }
};
