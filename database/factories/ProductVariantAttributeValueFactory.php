<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use App\Models\ProductVariantAttributeValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariantAttributeValue>
 */
class ProductVariantAttributeValueFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_variant_id' => ProductVariant::factory(),
            'product_attribute_value_id' => ProductAttributeValue::factory(),
        ];
    }
}
