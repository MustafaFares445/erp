<?php

declare(strict_types=1);

use App\Models\InventoryOperation;
use App\Models\InventoryReceipt;
use App\Models\StockTransfer;
use App\Services\Inventory\InventoryOperationBackfiller;
use Illuminate\Database\Migrations\Migration;

/**
 * Copies every existing {@see InventoryReceipt} and {@see StockTransfer}
 * into {@see InventoryOperation} rows (data-model.md §10, R-002).
 *
 * Non-destructive: the legacy tables are never written to. Rollback is dropping the new tables,
 * which the two `create_inventory_operations*` migrations already handle.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(InventoryOperationBackfiller::class)->backfill();
    }

    public function down(): void
    {
        // Intentionally a no-op: rolling back the table-creation migrations already removes
        // every row this backfill created. The legacy tables were never touched.
    }
};
