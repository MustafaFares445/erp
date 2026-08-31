<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InventoryReturn;
use App\Models\InventoryReturnLine;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryReturnLine>
 */
final class InventoryReturnLineFactory extends Factory
{
    protected $model = InventoryReturnLine::class;

    public function definition(): array
    {
        $variant = ProductVariant::factory()->create();

        return [
            'inventory_return_id' => InventoryReturn::factory(),
            'product_variant_id' => $variant->getKey(),
            'transaction_quantity' => '1.000000',
            'transaction_unit_id' => $variant->unit_id,
            'conversion_factor_snapshot' => '1.000000',
            'base_quantity' => '1.000000',
            'posted_base_quantity' => '0.000000',
        ];
    }
}
