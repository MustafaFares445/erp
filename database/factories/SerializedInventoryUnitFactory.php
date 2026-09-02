<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SerializedInventoryUnitStatus;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SerializedInventoryUnit> */
final class SerializedInventoryUnitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_variant_id' => ProductVariant::factory(),
            'warehouse_id' => null,
            'serial_number' => fake()->unique()->bothify('SER-########'),
            'iot_number' => null,
            'status' => SerializedInventoryUnitStatus::Pending,
        ];
    }
}
