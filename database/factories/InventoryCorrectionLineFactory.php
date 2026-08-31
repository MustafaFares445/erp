<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InventoryCorrection;
use App\Models\InventoryCorrectionLine;
use App\Models\InventoryMovement;
use App\Models\InventoryOperationLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryCorrectionLine>
 */
final class InventoryCorrectionLineFactory extends Factory
{
    protected $model = InventoryCorrectionLine::class;

    public function definition(): array
    {
        $movement = InventoryMovement::factory()->create();
        $operationLine = InventoryOperationLine::factory()->create();

        return [
            'inventory_correction_id' => InventoryCorrection::factory(),
            'original_inventory_movement_id' => $movement->getKey(),
            'original_inventory_operation_line_id' => $operationLine->getKey(),
            'product_variant_id' => $movement->product_variant_id,
            'warehouse_id' => $movement->warehouse_id,
            'transaction_quantity' => '1.000000',
            'transaction_unit_id' => $operationLine->unit_id,
            'conversion_factor_snapshot' => '1.000000',
            'base_quantity' => '1.000000',
            'posted_base_quantity' => '0.000000',
        ];
    }
}
