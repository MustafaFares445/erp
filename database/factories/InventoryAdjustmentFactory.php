<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AdjustmentStatus;
use App\Models\InventoryAdjustment;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryAdjustment>
 */
final class InventoryAdjustmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::factory(),
            'adjustment_number' => null,
            'reason' => fake()->sentence(),
            'status' => AdjustmentStatus::Draft,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'adjustment_number' => 'ADJ-'.mb_str_pad((string) fake()->unique()->numberBetween(1, 999_999), 6, '0', STR_PAD_LEFT),
            'status' => AdjustmentStatus::Confirmed,
        ]);
    }
}
