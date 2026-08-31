<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_operations', function (Blueprint $table): void {
            $table->timestamp('received_at')->nullable()->after('dispatched_at');
            $table->index(
                ['operation_type', 'stage', 'destination_warehouse_id'],
                'inventory_operations_active_transfer_destination_index',
            );
        });

        Schema::table('inventory_operation_lines', function (Blueprint $table): void {
            $table->decimal('dispatched_base_quantity', 20, 6)->nullable()->after('base_quantity');
            $table->decimal('received_base_quantity', 20, 6)->default(0)->after('dispatched_base_quantity');
            $table->foreignId('source_inventory_lot_id')->nullable()->after('inventory_lot_id')->constrained('inventory_lots')->nullOnDelete();
            $table->foreignId('destination_inventory_lot_id')->nullable()->after('source_inventory_lot_id')->constrained('inventory_lots')->nullOnDelete();
            $table->string('discrepancy_disposition', 20)->nullable()->after('received_base_quantity');
            $table->text('discrepancy_reason')->nullable()->after('discrepancy_disposition');
            $table->index(
                ['inventory_operation_id', 'discrepancy_disposition'],
                'inventory_operation_lines_transfer_discrepancy_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('inventory_operation_lines', function (Blueprint $table): void {
            $table->dropIndex('inventory_operation_lines_transfer_discrepancy_index');
            $table->dropConstrainedForeignId('destination_inventory_lot_id');
            $table->dropConstrainedForeignId('source_inventory_lot_id');
            $table->dropColumn([
                'dispatched_base_quantity',
                'received_base_quantity',
                'discrepancy_disposition',
                'discrepancy_reason',
            ]);
        });

        Schema::table('inventory_operations', function (Blueprint $table): void {
            $table->dropIndex('inventory_operations_active_transfer_destination_index');
            $table->dropColumn('received_at');
        });
    }
};
