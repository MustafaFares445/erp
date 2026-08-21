<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'inventory_movements',
            'inventory_receipt_items',
            'inventory_lots',
            'serialized_inventory_units',
            'stock_transfer_items',
            'inventory_adjustment_items',
            'inventory_operation_lines',
            'packages',
        ];

        if (DB::connection()->getDriverName() !== 'mysql') {
            Schema::table('packages', function (Blueprint $table): void {
                $table->dropIndex('packages_warehouse_id_warehouse_location_id_index');
            });

            foreach ($tables as $tableName) {
                if (Schema::hasColumn($tableName, 'warehouse_location_id')) {
                    Schema::table($tableName, function (Blueprint $table): void {
                        $table->dropForeign(['warehouse_location_id']);
                    });
                    Schema::table($tableName, function (Blueprint $table): void {
                        $table->dropColumn('warehouse_location_id');
                    });
                }
            }

            Schema::dropIfExists('warehouse_locations');

            return;
        }

        foreach ($tables as $tableName) {
            $foreignKeys = DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
                [$tableName, 'warehouse_location_id'],
            );

            foreach ($foreignKeys as $foreignKey) {
                if (! is_object($foreignKey)) {
                    continue;
                }

                $constraintName = get_object_vars($foreignKey)['CONSTRAINT_NAME'] ?? null;

                if (! is_string($constraintName)) {
                    continue;
                }

                Schema::table($tableName, function (Blueprint $table) use ($constraintName): void {
                    $table->dropForeign($constraintName);
                });
            }
        }

        if (DB::select('SHOW INDEX FROM `packages` WHERE Key_name = ?', ['packages_warehouse_id_warehouse_location_id_index']) !== []) {
            Schema::table('packages', function (Blueprint $table): void {
                $table->index('warehouse_id', 'packages_warehouse_id_index');
                $table->dropIndex('packages_warehouse_id_warehouse_location_id_index');
            });
        }

        if (DB::select('SHOW INDEX FROM `packages` WHERE Key_name = ?', ['packages_warehouse_location_id_foreign']) !== []) {
            Schema::table('packages', function (Blueprint $table): void {
                $table->dropIndex('packages_warehouse_location_id_foreign');
            });
        }

        foreach ($tables as $tableName) {
            if (Schema::hasColumn($tableName, 'warehouse_location_id')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->dropColumn('warehouse_location_id');
                });
            }
        }

        Schema::dropIfExists('warehouse_locations');
    }
};
