<?php

declare(strict_types=1);

use App\Enums\ProductType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Splits batch/lot identity off from expiry tracking (FR: a bulk material without an expiry
 * date, such as a sack of dental stone powder, is still traceable to the batch it arrived in).
 *
 * Backfilled from each variant's product type rather than from `track_expiry`, since an
 * expiry material already carries a lot and a grain now needs one too — only a machine, whose
 * variants are identified unit by unit, carries neither.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->boolean('track_batches')->default(false)->after('track_expiry');
        });

        DB::table('product_variants')
            ->whereExists(fn (Builder $query): Builder => $query
                ->select(DB::raw('1'))
                ->from('products')
                ->whereColumn('products.id', 'product_variants.product_id')
                ->where('products.product_type', '!=', ProductType::Machine->value))
            ->update(['track_batches' => true]);
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn('track_batches');
        });
    }
};
