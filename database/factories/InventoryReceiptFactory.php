<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReceiptStatus;
use App\Models\InventoryReceipt;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryReceipt> */
final class InventoryReceiptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::factory(),
            'supplier_id' => null,
            'receipt_number' => null,
            'supplier_reference' => fake()->optional()->bothify('REF-####'),
            'notes' => fake()->optional()->sentence(),
            'status' => ReceiptStatus::Draft,
        ];
    }
}
