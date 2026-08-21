<?php

declare(strict_types=1);

use App\Enums\ProductType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Assigns a {@see ProductType} to every pre-existing product from the tracking flags its
 * variants already carry, mirroring {@see ProductType::fromTrackingFlags()}.
 *
 * The assignment is deliberately flag-derived rather than guessed, which makes it safe:
 * the flags a legacy variant already has are exactly the flags its assigned type implies,
 * so no row's tracking behaviour changes and no product gains a constraint it previously
 * failed. Products left as `grain` keep `net_weight` null until an administrator edits
 * them; weight is derived reporting data, never an input to a stock balance, so nothing
 * breaks in the meantime.
 *
 * Soft-deleted variants count towards classification — a deleted variant still describes
 * what the product physically is.
 *
 * Idempotent: re-running produces the same result. `down()` is intentionally empty because
 * the column-adding migration drops the column outright.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')
            ->whereExists(fn (Builder $query): Builder => $this->variantsWithFlag($query, 'track_expiry'))
            ->update(['product_type' => ProductType::ExpiryMaterial->value]);

        // Applied second so serial tracking wins for a product carrying both flags,
        // matching ProductType::fromTrackingFlags().
        DB::table('products')
            ->whereExists(fn (Builder $query): Builder => $this->variantsWithFlag($query, 'track_serials'))
            ->update(['product_type' => ProductType::Machine->value]);
    }

    public function down(): void {}

    private function variantsWithFlag(Builder $query, string $column): Builder
    {
        return $query
            ->select(DB::raw('1'))
            ->from('product_variants')
            ->whereColumn('product_variants.product_id', 'products.id')
            ->where($column, true);
    }
};
