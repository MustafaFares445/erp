<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\ProductVariant;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryOperationLine>
 */
final class InventoryOperationLineFactory extends Factory
{
    protected $model = InventoryOperationLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inventory_operation_id' => InventoryOperation::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'quantity' => fake()->randomFloat(3, 0.1, 50),
            'unit_id' => Unit::factory(),
            'is_picked' => false,
        ];
    }
}
