<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MovementType;
use App\Models\InventoryMovement;
use App\Models\MaintenanceTask;
use App\Models\ProductVariant;
use App\Models\ServiceRecordPart;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceRecordPart>
 */
final class ServiceRecordPartFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $productVariant = ProductVariant::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $quantity = fake()->randomFloat(3, 1, 10);

        $movement = InventoryMovement::query()->forceCreate([
            'product_variant_id' => $productVariant->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'movement_type' => MovementType::ServiceConsumption,
            'quantity' => -$quantity,
            'source_type' => 'service_record_part',
            'status' => 'confirmed',
        ]);

        return [
            'maintenance_task_id' => MaintenanceTask::factory(),
            'product_variant_id' => $productVariant->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'quantity' => $quantity,
            'inventory_movement_id' => $movement->getKey(),
        ];
    }

    public function reversed(): static
    {
        return $this->state(function (array $attributes): array {
            $reversalMovement = InventoryMovement::query()->forceCreate([
                'product_variant_id' => $attributes['product_variant_id'],
                'warehouse_id' => $attributes['warehouse_id'],
                'movement_type' => MovementType::ServiceConsumption,
                'quantity' => $attributes['quantity'],
                'source_type' => 'service_record_part',
                'status' => 'confirmed',
            ]);

            return [
                'reversed_at' => now(),
                'reversed_by' => User::factory()->admin(),
                'reversal_movement_id' => $reversalMovement->getKey(),
            ];
        });
    }
}
