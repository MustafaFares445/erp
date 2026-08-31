<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_returns', function (Blueprint $table): void {
            $table->id();
            $table->string('return_number', 40)->unique();
            $table->string('return_type', 20);
            $table->string('status', 20)->default('draft');
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customer_profiles')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('original_inventory_operation_id')
                ->nullable()
                ->constrained('inventory_operations')
                ->restrictOnDelete();
            $table->foreignId('original_purchase_order_id')
                ->nullable()
                ->constrained('purchase_orders')
                ->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->string('financial_reference_type', 100)->nullable();
            $table->unsignedBigInteger('financial_reference_id')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['return_type', 'status'], 'inventory_returns_type_status_index');
            $table->index(['customer_id', 'status'], 'inventory_returns_customer_status_index');
            $table->index(['supplier_id', 'status'], 'inventory_returns_supplier_status_index');
            $table->index(
                ['financial_reference_type', 'financial_reference_id'],
                'inventory_returns_financial_reference_index',
            );
        });

        Schema::create('inventory_return_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_return_id')
                ->constrained('inventory_returns')
                ->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('transaction_quantity', 20, 6);
            $table->foreignId('transaction_unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('conversion_factor_snapshot', 20, 6);
            $table->decimal('base_quantity', 20, 6);
            $table->string('source_condition', 20)->nullable();
            $table->string('disposition', 20)->nullable();
            $table->foreignId('inventory_lot_id')
                ->nullable()
                ->constrained('inventory_lots')
                ->restrictOnDelete();
            $table->foreignId('serialized_inventory_unit_id')
                ->nullable()
                ->constrained('serialized_inventory_units')
                ->restrictOnDelete();
            $table->foreignId('original_inventory_operation_line_id')->nullable();
            $table->foreign(
                'original_inventory_operation_line_id',
                'inventory_return_lines_original_operation_line_foreign',
            )
                ->references('id')
                ->on('inventory_operation_lines')
                ->restrictOnDelete();
            $table->foreignId('original_inventory_movement_id')
                ->nullable()
                ->constrained('inventory_movements')
                ->restrictOnDelete();
            $table->text('inspection_notes')->nullable();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('inspected_at')->nullable();
            $table->decimal('posted_base_quantity', 20, 6)->default(0);
            $table->foreignId('posted_inventory_movement_id')
                ->nullable()
                ->constrained('inventory_movements')
                ->restrictOnDelete();
            $table->timestamps();

            $table->index(
                ['original_inventory_operation_line_id', 'inventory_return_id'],
                'inventory_return_lines_original_line_index',
            );
            $table->index(
                ['serialized_inventory_unit_id', 'inventory_return_id'],
                'inventory_return_lines_serial_index',
            );
            $table->index(
                ['inventory_lot_id', 'inventory_return_id'],
                'inventory_return_lines_lot_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_return_lines');
        Schema::dropIfExists('inventory_returns');
    }
};
