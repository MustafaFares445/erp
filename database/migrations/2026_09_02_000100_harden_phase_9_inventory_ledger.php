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
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->index(
                ['product_variant_id', 'warehouse_id', 'created_at'],
                'inventory_movements_balance_timeline_index',
            );
            $table->index(
                ['stock_condition_from', 'stock_condition_to', 'created_at'],
                'inventory_movements_condition_timeline_index',
            );
            $table->index(
                ['inventory_lot_id', 'serialized_inventory_unit_id', 'package_id'],
                'inventory_movements_allocation_lookup_index',
            );
            $table->index(
                ['source_type', 'source_id', 'source_line_type', 'source_line_id'],
                'inventory_movements_source_trace_index',
            );
        });

        if (! $this->supportsEnforcedMySqlChecks()) {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE inventory_condition_balances
            ADD CONSTRAINT inventory_condition_balances_quantities_check
            CHECK (
                on_hand_base_quantity >= 0
                AND reserved_base_quantity >= 0
                AND reserved_base_quantity <= on_hand_base_quantity
                AND (stock_condition = 'saleable' OR reserved_base_quantity = 0)
                AND stock_condition IN ('saleable', 'quarantine', 'damaged')
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE inventory_lot_balances
            ADD CONSTRAINT inventory_lot_balances_quantities_check
            CHECK (
                on_hand_base_quantity >= 0
                AND reserved_base_quantity >= 0
                AND reserved_base_quantity <= on_hand_base_quantity
                AND (stock_condition = 'saleable' OR reserved_base_quantity = 0)
                AND stock_condition IN ('saleable', 'quarantine', 'damaged')
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE inventory_movements
            ADD CONSTRAINT inventory_movements_uom_snapshot_check
            CHECK (
                (
                    transaction_quantity IS NULL
                    AND transaction_unit_id IS NULL
                    AND conversion_factor_snapshot IS NULL
                    AND base_quantity_delta IS NULL
                )
                OR
                (
                    transaction_quantity IS NOT NULL
                    AND transaction_unit_id IS NOT NULL
                    AND conversion_factor_snapshot IS NOT NULL
                    AND base_quantity_delta IS NOT NULL
                    AND transaction_quantity > 0
                    AND conversion_factor_snapshot > 0
                    AND base_quantity_delta = quantity
                    AND ABS(base_quantity_delta) = transaction_quantity * conversion_factor_snapshot
                )
            )
        SQL);
    }

    public function down(): void
    {
        if ($this->supportsEnforcedMySqlChecks()) {
            DB::statement(
                'ALTER TABLE inventory_movements DROP CHECK inventory_movements_uom_snapshot_check',
            );
            DB::statement(
                'ALTER TABLE inventory_lot_balances DROP CHECK inventory_lot_balances_quantities_check',
            );
            DB::statement(
                'ALTER TABLE inventory_condition_balances DROP CHECK inventory_condition_balances_quantities_check',
            );
        }

        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropIndex('inventory_movements_source_trace_index');
            $table->dropIndex('inventory_movements_allocation_lookup_index');
            $table->dropIndex('inventory_movements_condition_timeline_index');
            $table->dropIndex('inventory_movements_balance_timeline_index');
        });
    }

    private function supportsEnforcedMySqlChecks(): bool
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return false;
        }

        $result = DB::selectOne('SELECT VERSION() AS version');
        $version = is_object($result) ? ($result->version ?? null) : null;

        if (! is_string($version) || str_contains(mb_strtolower($version), 'mariadb')) {
            return false;
        }

        if (preg_match('/(\d+\.\d+\.\d+)/', $version, $matches) !== 1) {
            return false;
        }

        return version_compare($matches[1], '8.0.16', '>=');
    }
};
