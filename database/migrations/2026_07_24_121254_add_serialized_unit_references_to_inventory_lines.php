<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stock_transfer_items')) {
            Schema::table('stock_transfer_items', function (Blueprint $table): void {
                $table->foreignId('serialized_inventory_unit_id')->nullable()->after('product_variant_id')->constrained('serialized_inventory_units')->restrictOnDelete();
                $table->unique('serialized_inventory_unit_id', 'transfer_item_serial_unit_unique');
            });
        }

        Schema::table('inventory_adjustment_items', function (Blueprint $table): void {
            $table->foreignId('serialized_inventory_unit_id')->nullable()->after('product_variant_id')->constrained('serialized_inventory_units')->restrictOnDelete();
            $table->unique('serialized_inventory_unit_id', 'adjustment_item_serial_unit_unique');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_adjustment_items', function (Blueprint $table): void {
            $table->dropUnique('adjustment_item_serial_unit_unique');
            $table->dropConstrainedForeignId('serialized_inventory_unit_id');
        });

        if (Schema::hasTable('stock_transfer_items')) {
            Schema::table('stock_transfer_items', function (Blueprint $table): void {
                $table->dropUnique('transfer_item_serial_unit_unique');
                $table->dropConstrainedForeignId('serialized_inventory_unit_id');
            });
        }
    }
};
