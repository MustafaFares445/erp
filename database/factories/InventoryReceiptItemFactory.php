<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptItem;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryReceiptItem> */
final class InventoryReceiptItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'inventory_receipt_id' => InventoryReceipt::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'unit_id' => null,
            'quantity' => 1,
            'purchase_cost' => 10,
            'currency_code' => 'USD',
            'expires_at' => null,
            'lot_number' => null,
        ];
    }
}
