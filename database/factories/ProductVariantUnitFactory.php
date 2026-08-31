<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\ProductVariantUnit;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariantUnit>
 */
final class ProductVariantUnitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_variant_id' => ProductVariant::factory(),
            'unit_id' => Unit::factory(),
            'is_base' => false,
            'is_purchase' => true,
            'is_sale' => true,
            'is_display' => false,
            'factor_to_base' => '1.000000',
            'rounding_increment' => '0.001000',
            'permits_cross_family_conversion' => false,
            'is_active' => true,
            'effective_from' => now(),
        ];
    }
}
