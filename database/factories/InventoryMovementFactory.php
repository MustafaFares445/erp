<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MovementType;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryMovement>
 */
final class InventoryMovementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_variant_id' => ProductVariant::factory(),
            'warehouse_id' => Warehouse::factory(),
            'movement_type' => fake()->randomElement(MovementType::cases()),
            'quantity' => fake()->randomFloat(3, 1, 50),
            'source_type' => null,
            'source_id' => null,
            'notes' => null,
            'status' => 'confirmed',
        ];
    }

    public function sale(): static
    {
        return $this->state(fn (array $attributes): array => [
            'movement_type' => MovementType::Sale,
            'quantity' => -fake()->randomFloat(3, 1, 50),
        ]);
    }

    public function return(): static
    {
        return $this->state(fn (array $attributes): array => [
            'movement_type' => MovementType::Return,
            'quantity' => fake()->randomFloat(3, 1, 50),
        ]);
    }

    public function adjustment(): static
    {
        return $this->state(fn (array $attributes): array => [
            'movement_type' => MovementType::Adjustment,
        ]);
    }

    public function transfer(): static
    {
        return $this->state(fn (array $attributes): array => [
            'movement_type' => MovementType::Transfer,
        ]);
    }

    public function reservation(): static
    {
        return $this->state(fn (array $attributes): array => [
            'movement_type' => MovementType::Reservation,
        ]);
    }

    /**
     * Attributes a movement to a cross-module source document (e.g. a
     * delivery note) rendered as a read-only link (FR-019).
     */
    public function fromSource(string $sourceType, int $sourceId): static
    {
        return $this->state(fn (array $attributes): array => [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ]);
    }
}
