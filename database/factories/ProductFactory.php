<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProductType;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
final class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'product_type' => ProductType::Grain,
            'is_active' => true,
        ];
    }

    /** Serialized equipment: every unit carries a serial number. */
    public function machine(): self
    {
        return $this->state(['product_type' => ProductType::Machine]);
    }

    /** Consumable material received in lots that expire. */
    public function expiryMaterial(): self
    {
        return $this->state(['product_type' => ProductType::ExpiryMaterial]);
    }

    /** Bulk goods sold by weight. */
    public function grain(): self
    {
        return $this->state(['product_type' => ProductType::Grain]);
    }
}
