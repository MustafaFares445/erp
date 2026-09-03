<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InventoryReturnStatus;
use App\Enums\InventoryReturnType;
use App\Models\InventoryReturn;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryReturn>
 */
final class InventoryReturnFactory extends Factory
{
    protected $model = InventoryReturn::class;

    public function definition(): array
    {
        return [
            'return_number' => 'RET-'.fake()->unique()->numerify('######'),
            'return_type' => InventoryReturnType::Customer,
            'status' => InventoryReturnStatus::Draft,
            'warehouse_id' => Warehouse::factory(),
            'reason' => fake()->optional()->sentence(),
            'notes' => fake()->optional()->sentence(),
            'credit_note_required' => false,
        ];
    }

    public function customer(): static
    {
        return $this->state(fn (): array => [
            'return_type' => InventoryReturnType::Customer,
            'supplier_id' => null,
        ]);
    }

    public function supplier(): static
    {
        return $this->state(fn (): array => [
            'return_type' => InventoryReturnType::Supplier,
            'customer_id' => null,
        ]);
    }

    public function ready(): static
    {
        return $this->state(fn (): array => [
            'status' => InventoryReturnStatus::Ready,
            'ready_at' => now(),
        ]);
    }

    public function posted(): static
    {
        return $this->state(fn (): array => [
            'status' => InventoryReturnStatus::Posted,
            'ready_at' => now()->subMinute(),
            'posted_at' => now(),
        ]);
    }
}
