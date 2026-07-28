<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierProductReference>
 */
class SupplierProductReferenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'supplier_name' => fake()->company(),
            'supplier_item_number' => mb_strtoupper(fake()->unique()->bothify('ITEM-####')),
            'country_code' => fake()->countryCode(),
            'manufacturer' => fake()->company(),
            'purchase_cost' => fake()->randomFloat(2, 1, 200),
            'currency_code' => 'USD',
            'notes' => null,
            'is_active' => true,
        ];
    }
}
