<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `bills_supplier_reference_unique` (from `2026_09_04_100500_enforce_supplier_reference_uniqueness`)
 * enforces uniqueness of `(supplier_id, supplier_reference)` unconditionally. That blocks the
 * legitimate case `Bill::booted()`'s `saving` guard already allows: a cancelled bill's evidence
 * reference is free to be reused by its replacement, because the cancelled row no longer
 * represents a live financial claim (Bill::isFinanciallyImmutable() never even considers it).
 *
 * Neither driver supports the same mechanism for a conditional unique index. SQLite has real
 * partial indexes; MySQL does not, so the predicate is materialised into a virtual generated
 * column that is NULL for a cancelled bill — and NULLs do not collide in a unique index. This
 * mirrors `2026_08_20_090006_add_active_supplier_product_reference_unique_index`.
 */
return new class extends Migration
{
    private const string INDEX = 'bills_supplier_reference_active_unique';

    private const string GENERATED_COLUMN = 'active_supplier_reference';

    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table): void {
            $table->dropUnique('bills_supplier_reference_unique');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
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

            return;
        }

        DB::statement(sprintf(
            "CREATE UNIQUE INDEX %s ON bills (supplier_id, supplier_reference) WHERE status != 'cancelled'",
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
        } else {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
        }

        Schema::table('bills', function (Blueprint $table): void {
            $table->unique(
                ['supplier_id', 'supplier_reference'],
                'bills_supplier_reference_unique',
            );
        });
    }
};
