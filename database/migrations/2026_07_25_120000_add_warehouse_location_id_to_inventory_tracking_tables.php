<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wires the previously unreferenced `warehouse_locations` table into the
 * movement/document layer. Additive and nullable everywhere: existing rows,
 * queries, and the `inventory_stocks` balance grain are untouched. A location
 * marks *where within the warehouse* a specific movement, lot, serialized
 * unit, or adjustment line applies — it does not
 * change how warehouse-level balances are kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->foreignId('warehouse_location_id')->nullable()->after('warehouse_id')
                ->constrained('warehouse_locations')->nullOnDelete();
        });

        Schema::table('inventory_lots', function (Blueprint $table): void {
            $table->foreignId('warehouse_location_id')->nullable()->after('warehouse_id')
                ->constrained('warehouse_locations')->nullOnDelete();
        });

        Schema::table('serialized_inventory_units', function (Blueprint $table): void {
            $table->foreignId('warehouse_location_id')->nullable()->after('warehouse_id')
                ->constrained('warehouse_locations')->nullOnDelete();
        });

        Schema::table('inventory_adjustment_items', function (Blueprint $table): void {
            $table->foreignId('warehouse_location_id')->nullable()->after('serialized_inventory_unit_id')
                ->constrained('warehouse_locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        foreach ([
            'inventory_movements',
            'inventory_lots',
            'serialized_inventory_units',
            'inventory_adjustment_items',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('warehouse_location_id');
            });
        }
    }
};
