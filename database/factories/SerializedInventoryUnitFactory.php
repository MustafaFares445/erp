<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InventoryReceiptItem;
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
            'inventory_receipt_item_id' => InventoryReceiptItem::factory(),
            'serial_number' => fake()->unique()->bothify('SER-########'),
            'iot_number' => null,
            'status' => 'pending',
        ];
    }
}
