<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CountScope;
use App\Enums\InventoryCountStatus;
use App\Enums\StockCondition;
use App\Models\InventoryCount;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryCount>
 */
final class InventoryCountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'count_number' => 'CNT-'.mb_str_pad((string) fake()->unique()->numberBetween(1, 999_999), 6, '0', STR_PAD_LEFT),
            'status' => InventoryCountStatus::Draft,
            'scope_type' => CountScope::Warehouse,
            'warehouse_id' => Warehouse::factory(),
            'conditions' => [StockCondition::Saleable->value],
            'is_partial' => false,
            'opened_at' => now(),
            'created_by' => User::factory(),
        ];
    }

    public function counting(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => InventoryCountStatus::Counting]);
    }

    public function pendingReview(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => InventoryCountStatus::PendingReview]);
    }
}
