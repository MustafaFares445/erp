<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProductAttribute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductAttribute>
 */
class ProductAttributeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'name_ar' => null,
            'code' => mb_strtoupper(fake()->unique()->bothify('ATTR-###')),
            'data_type' => 'select',
            'is_active' => true,
        ];
    }
}
