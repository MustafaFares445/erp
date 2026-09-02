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
                $table->dropForeign(['serialized_inventory_unit_id']);
                $table->dropUnique('transfer_item_serial_unit_unique');
                $table->foreign('serialized_inventory_unit_id')
                    ->references('id')
                    ->on('serialized_inventory_units')
                    ->restrictOnDelete();
            });
        }

        Schema::table('inventory_adjustment_items', function (Blueprint $table): void {
            $table->dropForeign(['serialized_inventory_unit_id']);
            $table->dropUnique('adjustment_item_serial_unit_unique');
            $table->foreign('serialized_inventory_unit_id')
                ->references('id')
                ->on('serialized_inventory_units')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('stock_transfer_items')) {
            Schema::table('stock_transfer_items', function (Blueprint $table): void {
                $table->unique('serialized_inventory_unit_id', 'transfer_item_serial_unit_unique');
            });
        }

        Schema::table('inventory_adjustment_items', function (Blueprint $table): void {
            $table->unique('serialized_inventory_unit_id', 'adjustment_item_serial_unit_unique');
        });
    }
};
