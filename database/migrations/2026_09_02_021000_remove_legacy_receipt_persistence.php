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

        if (Schema::hasColumn('inventory_operations', 'legacy_receipt_id')) {
            Schema::table('inventory_operations', function (Blueprint $table): void {
                $table->dropColumn('legacy_receipt_id');
            });
        }

        Schema::dropIfExists('inventory_receipt_items');
        Schema::dropIfExists('inventory_receipts');
    }

    public function down(): void
    {
        // Legacy receipt persistence is intentionally not recreated.
    }
};
