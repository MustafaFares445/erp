<?php

declare(strict_types=1);

use App\Models\Bill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A PO-generated bill must not duplicate supplier ownership (Phase 0
 * remediation): `Bill.supplier_id` was a second, independently-settable
 * source of truth for a fact the linked purchase order already recorded,
 * and nothing stopped the two from disagreeing.
 *
 * `supplier_id` becomes optional and stays that way only for a standalone
 * bill (no `purchase_order_id`); a PO-linked bill must leave it null and let
 * {@see Bill}'s `saving` hook derive `resolved_supplier_id` from
 * `purchaseOrder->supplier_id` instead. `resolved_supplier_id` is what every
 * supplier-scoped query — the duplicate-reference control included — reads
 * from here on, because it is the one column guaranteed to hold the true
 * supplier regardless of which of the two sources the bill came from.
 */
return new class extends Migration
{
    private const string INDEX = 'bills_supplier_reference_active_unique';

    private const string GENERATED_COLUMN = 'active_supplier_reference';

    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table): void {
            $table->foreignId('supplier_id')->nullable()->change();
            $table->foreignId('resolved_supplier_id')->after('supplier_id')->constrained('suppliers')->restrictOnDelete();
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('DROP INDEX '.self::INDEX.' ON bills');
            Schema::table('bills', function (Blueprint $table): void {
                $table->dropColumn(self::GENERATED_COLUMN);
            });

            DB::statement(sprintf(
                'ALTER TABLE bills ADD COLUMN %s VARCHAR(100) GENERATED ALWAYS AS '
                ."(CASE WHEN status != 'cancelled' THEN supplier_reference ELSE NULL END) VIRTUAL",
                self::GENERATED_COLUMN
            ));

            DB::statement(sprintf(
                'CREATE UNIQUE INDEX %s ON bills (resolved_supplier_id, %s)',
                self::INDEX,
                self::GENERATED_COLUMN
            ));

            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);

        DB::statement(sprintf(
            "CREATE UNIQUE INDEX %s ON bills (resolved_supplier_id, supplier_reference) WHERE status != 'cancelled'",
            self::INDEX
        ));
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('DROP INDEX '.self::INDEX.' ON bills');
            Schema::table('bills', function (Blueprint $table): void {
                $table->dropColumn(self::GENERATED_COLUMN);
            });

            DB::statement(sprintf(
                'ALTER TABLE bills ADD COLUMN %s VARCHAR(100) GENERATED ALWAYS AS '
                ."(CASE WHEN status != 'cancelled' THEN supplier_reference ELSE NULL END) VIRTUAL",
                self::GENERATED_COLUMN
            ));

            DB::statement(sprintf(
                'CREATE UNIQUE INDEX %s ON bills (supplier_id, %s)',
                self::INDEX,
                self::GENERATED_COLUMN
            ));
        } else {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX);

            DB::statement(sprintf(
                "CREATE UNIQUE INDEX %s ON bills (supplier_id, supplier_reference) WHERE status != 'cancelled'",
                self::INDEX
            ));
        }

        Schema::table('bills', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('resolved_supplier_id');
            $table->foreignId('supplier_id')->nullable(false)->change();
        });
    }
};
