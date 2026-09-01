<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->foreignId('reversal_of_movement_id')
                ->nullable()
                ->after('source_line_id')
                ->constrained('inventory_movements')
                ->restrictOnDelete();

            $table->index(
                ['reversal_of_movement_id', 'movement_type'],
                'inventory_movements_reversal_type_index',
            );
        });

        Schema::create('inventory_corrections', function (Blueprint $table): void {
            $table->id();
            $table->string('correction_number', 40)->unique();
            $table->string('correction_type', 20);
            $table->string('status', 20)->default('draft');
            $table->foreignId('original_inventory_operation_id')
                ->constrained('inventory_operations')
                ->restrictOnDelete();
            $table->text('reason');
            $table->text('notes')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(
                ['original_inventory_operation_id', 'status'],
                'inventory_corrections_original_status_index',
            );
        });

        Schema::create('inventory_correction_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_correction_id')
                ->constrained('inventory_corrections')
                ->cascadeOnDelete();
            $table->foreignId('original_inventory_movement_id')
                ->constrained(
                    table: 'inventory_movements',
                    indexName: 'inventory_correction_lines_original_movement_foreign',
                )
                ->restrictOnDelete();
            $table->foreignId('original_inventory_operation_line_id')
                ->constrained(
                    table: 'inventory_operation_lines',
                    indexName: 'inventory_correction_lines_original_operation_line_foreign',
                )
                ->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->decimal('transaction_quantity', 20, 6);
            $table->foreignId('transaction_unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('conversion_factor_snapshot', 20, 6);
            $table->decimal('base_quantity', 20, 6);
            $table->foreignId('inventory_lot_id')
                ->nullable()
                ->constrained('inventory_lots')
                ->restrictOnDelete();
            $table->foreignId('serialized_inventory_unit_id')
                ->nullable()
                ->constrained('serialized_inventory_units')
                ->restrictOnDelete();
            $table->decimal('posted_base_quantity', 20, 6)->default(0);
            $table->foreignId('posted_inventory_movement_id')
                ->nullable()
                ->constrained('inventory_movements')
                ->restrictOnDelete();
            $table->timestamps();

            $table->index(
                ['original_inventory_movement_id', 'inventory_correction_id'],
                'inventory_correction_lines_original_movement_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_correction_lines');
        Schema::dropIfExists('inventory_corrections');

        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropIndex('inventory_movements_reversal_type_index');
            $table->dropConstrainedForeignId('reversal_of_movement_id');
        });
    }
};
