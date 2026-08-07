<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiKeywordRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiKeywordRule>
 */
final class AiKeywordRuleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'keyword' => fake()->unique()->word(),
            'product_id' => null,
            'product_variant_id' => null,
            'is_active' => true,
        ];
    }
}
