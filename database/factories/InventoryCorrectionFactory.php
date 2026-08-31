<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InventoryCorrectionStatus;
use App\Enums\InventoryCorrectionType;
use App\Models\InventoryCorrection;
use App\Models\InventoryOperation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryCorrection>
 */
final class InventoryCorrectionFactory extends Factory
{
    protected $model = InventoryCorrection::class;

    public function definition(): array
    {
        return [
            'correction_number' => 'COR-'.fake()->unique()->numerify('######'),
            'correction_type' => InventoryCorrectionType::Receipt,
            'status' => InventoryCorrectionStatus::Draft,
            'original_inventory_operation_id' => InventoryOperation::factory()->receipt()->done(),
            'reason' => fake()->sentence(),
        ];
    }

    public function posted(): static
    {
        return $this->state(fn (): array => [
            'status' => InventoryCorrectionStatus::Posted,
            'posted_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => InventoryCorrectionStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => fake()->sentence(),
        ]);
    }
}
