<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('serialized_inventory_units', 'inventory_receipt_item_id')) {
            Schema::table('serialized_inventory_units', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('inventory_receipt_item_id');
            });
        }

        foreach (['legacy_receipt_id', 'legacy_transfer_id'] as $column) {
            if (Schema::hasColumn('inventory_operations', $column)) {
                Schema::table('inventory_operations', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }

        if (Schema::hasColumn('inventory_reservations', 'legacy_stock_reservation_id')) {
            Schema::table('inventory_reservations', function (Blueprint $table): void {
                $table->dropColumn('legacy_stock_reservation_id');
            });
        }

        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('inventory_receipt_items');
        Schema::dropIfExists('inventory_receipts');
    }

    public function down(): void
    {
        // Phase 10 deliberately removes abandoned pre-production persistence.
        // Restoring these tables would recreate a second inventory architecture,
        // so rollback is performed from source control/database backup instead.
    }
};
