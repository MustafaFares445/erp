<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enforces V-14: at most one *active* supplier product reference per
 * `(supplier_id, product_variant_id)`.
 *
 * The existing unique index is on `(supplier_id, supplier_item_number)`, which
 * does not stop the same variant being referenced twice under two item
 * numbers. Cost writeback (FR-048) needs a single unambiguous target, so this
 * closes the gap.
 *
 * Neither driver supports the same mechanism. SQLite has real partial indexes;
 * MySQL does not, so the predicate is materialised into a virtual generated
 * column whose value is NULL for anything inactive — and NULLs do not collide
 * in a unique index.
 *
 * Pre-existing duplicates fail the migration loudly rather than being silently
 * deactivated. Choosing which of two active references survives is a data
 * decision, and making it invisibly inside a schema migration is how the wrong
 * cost ends up on every future purchase order.
 */
return new class extends Migration
{
    private const INDEX = 'supplier_reference_active_variant_unique';

    private const GENERATED_COLUMN = 'active_product_variant_id';

    public function up(): void
    {
        $this->guardAgainstExistingDuplicates();

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(sprintf(
                'ALTER TABLE supplier_product_references ADD COLUMN %s BIGINT UNSIGNED GENERATED ALWAYS AS '
                .'(CASE WHEN is_active = 1 AND deleted_at IS NULL THEN product_variant_id ELSE NULL END) VIRTUAL',
                self::GENERATED_COLUMN
            ));

            DB::statement(sprintf(
                'CREATE UNIQUE INDEX %s ON supplier_product_references (supplier_id, %s)',
                self::INDEX,
                self::GENERATED_COLUMN
            ));

            return;
        }

        DB::statement(sprintf(
            'CREATE UNIQUE INDEX %s ON supplier_product_references (supplier_id, product_variant_id) '
            .'WHERE is_active = 1 AND deleted_at IS NULL',
            self::INDEX
        ));
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('DROP INDEX '.self::INDEX.' ON supplier_product_references');
            Schema::table('supplier_product_references', function ($table): void {
                $table->dropColumn(self::GENERATED_COLUMN);
            });

            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
    }

    private function guardAgainstExistingDuplicates(): void
    {
        $duplicates = DB::table('supplier_product_references')
            ->select('supplier_id', 'product_variant_id', DB::raw('COUNT(*) as total'))
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->groupBy('supplier_id', 'product_variant_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isEmpty()) {
            return;
        }

        $pairs = $duplicates
            ->map(static fn (object $row): string => sprintf(
                'supplier %d / variant %d (%d rows)',
                $row->supplier_id,
                $row->product_variant_id,
                $row->total,
            ))
            ->implode('; ');

        throw new RuntimeException(
            'Cannot enforce one active supplier product reference per supplier and variant: '
            .'existing duplicates must be resolved by hand first — '.$pairs
        );
    }
};
