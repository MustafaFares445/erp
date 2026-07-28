<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Models\InventoryOperation;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryOperation>
 */
final class InventoryOperationFactory extends Factory
{
    protected $model = InventoryOperation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'operation_type' => OperationType::Receipt,
            'stage' => OperationStage::Draft,
            'destination_warehouse_id' => Warehouse::factory(),
            'supplier_id' => Supplier::factory(),
            'scheduled_at' => now(),
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function receipt(): static
    {
        return $this->state(fn (array $attributes): array => [
            'operation_type' => OperationType::Receipt,
            'source_warehouse_id' => null,
            'destination_warehouse_id' => Warehouse::factory(),
            'supplier_id' => Supplier::factory(),
        ]);
    }

    public function delivery(): static
    {
        return $this->state(fn (array $attributes): array => [
            'operation_type' => OperationType::Delivery,
            'source_warehouse_id' => Warehouse::factory(),
            'destination_warehouse_id' => null,
            'supplier_id' => null,
        ]);
    }

    public function internalTransfer(): static
    {
        return $this->state(fn (array $attributes): array => [
            'operation_type' => OperationType::InternalTransfer,
            'source_warehouse_id' => Warehouse::factory(),
            'destination_warehouse_id' => Warehouse::factory(),
            'supplier_id' => null,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes): array => [
            'stage' => OperationStage::Draft,
        ]);
    }

    public function waiting(): static
    {
        return $this->state(fn (array $attributes): array => [
            'stage' => OperationStage::Waiting,
        ]);
    }

    public function ready(): static
    {
        return $this->state(fn (array $attributes): array => [
            'operation_number' => $this->nextOperationNumber(),
            'stage' => OperationStage::Ready,
        ]);
    }

    /**
     * Valid only for an internal transfer (V-03) — the caller is responsible for combining this
     * with {@see self::internalTransfer()}.
     */
    public function inTransit(): static
    {
        return $this->state(fn (array $attributes): array => [
            'operation_number' => $this->nextOperationNumber(),
            'stage' => OperationStage::InTransit,
            'dispatched_at' => now(),
        ]);
    }

    public function done(): static
    {
        return $this->state(fn (array $attributes): array => [
            'operation_number' => $this->nextOperationNumber(),
            'stage' => OperationStage::Done,
            'completed_at' => now(),
        ]);
    }

    public function canceled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'stage' => OperationStage::Canceled,
            'canceled_at' => now(),
        ]);
    }

    private function nextOperationNumber(): string
    {
        return 'OP-'.mb_str_pad((string) fake()->unique()->numberBetween(1, 999_999), 6, '0', STR_PAD_LEFT);
    }
}
