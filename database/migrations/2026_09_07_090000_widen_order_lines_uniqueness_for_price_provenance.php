<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `2026_09_02_030000_add_sales_uom_snapshots.php` widened the order-lines
     * uniqueness contract from `(order_id, product_variant_id)` to
     * `(order_id, product_variant_id, unit_id)` when the aggregation key
     * gained a UOM dimension. `QuotationConversionService::aggregateLines()`
     * (spec 019, price provenance) later widened that same key again to also
     * require an identical commercial price and price provenance before two
     * quotation lines may collapse into one order line — but the unique index
     * was never widened to match, so two quotation lines for the same variant
     * and unit at different prices (which the service now deliberately keeps
     * as separate order lines) fail on insert with a false duplicate.
     */
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->dropUnique('order_lines_order_variant_unit_unique');
            $table->unique(
                [
                    'order_id',
                    'product_variant_id',
                    'unit_id',
                    'unit_price',
                    'resolved_price_source',
                    'resolved_price_tier_id',
                    'price_floor_override_id',
                    'list_price_minor',
                    'floor_price_minor',
                ],
                'order_lines_order_variant_unit_price_provenance_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->dropUnique('order_lines_order_variant_unit_price_provenance_unique');
            $table->unique(['order_id', 'product_variant_id', 'unit_id'], 'order_lines_order_variant_unit_unique');
        });
    }
};
