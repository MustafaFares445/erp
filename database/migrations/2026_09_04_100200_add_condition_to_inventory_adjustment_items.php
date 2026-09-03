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
        Schema::table('inventory_adjustment_items', function (Blueprint $table): void {
            $table->string('stock_condition', 20)
                ->default('saleable')
                ->after('product_variant_id');
            $table->index(
                ['inventory_adjustment_id', 'stock_condition'],
                'inventory_adjustment_items_condition_idx',
            );
        });

        Schema::table('inventory_adjustments', function (Blueprint $table): void {
            $table->string('reason_category', 40)
                ->nullable()
                ->after('reason');
        });

        // Every historical adjustment was saleable-only because the previous
        // service had no path to write another condition. Keep that truthful
        // backfill, then require all new writers to supply the condition.
        DB::statement(
            'ALTER TABLE inventory_adjustment_items ALTER COLUMN stock_condition DROP DEFAULT',
        );
    }

    public function down(): void
    {
        Schema::table('inventory_adjustment_items', function (Blueprint $table): void {
            $table->dropIndex('inventory_adjustment_items_condition_idx');
            $table->dropColumn('stock_condition');
        });

        Schema::table('inventory_adjustments', function (Blueprint $table): void {
            $table->dropColumn('reason_category');
        });
    }
};
