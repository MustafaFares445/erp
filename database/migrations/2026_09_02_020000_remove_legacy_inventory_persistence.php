<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('inventory_operations', 'legacy_transfer_id')) {
            Schema::table('inventory_operations', function (Blueprint $table): void {
                $table->dropColumn('legacy_transfer_id');
            });
        }

        if (Schema::hasColumn('inventory_reservations', 'legacy_stock_reservation_id')) {
            Schema::table('inventory_reservations', function (Blueprint $table): void {
                $table->dropColumn('legacy_stock_reservation_id');
            });
        }

        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
    }

    public function down(): void
    {
        // Phase 10 deliberately removes abandoned pre-production persistence.
        // Restoring these tables would recreate a second inventory architecture,
        // so rollback is performed from source control/database backup instead.
    }
};
