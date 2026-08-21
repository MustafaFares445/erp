<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrderLine>
 */
final class PurchaseOrderLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->randomFloat(3, 1, 100);
        $unitCost = fake()->randomFloat(2, 1, 500);

        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'unit_id' => Unit::factory(),
            'supplier_product_reference_id' => null,
            'supplier_item_number' => null,
            'quantity_ordered' => $quantity,
            'quantity_received' => 0,
            'unit_cost' => $unitCost,
            // Kept consistent with quantity and cost by default so a factory-built
            // order's stored total is not nonsense; the service recomputes it on
            // every real write (R-008).
            'line_total' => round($quantity * $unitCost, 2),
            'expected_at' => null,
        ];
    }

    public function received(float $quantity, ?float $unitCost = null): self
    {
        return $this->state(fn (array $attributes): array => [
            'quantity_received' => $quantity,
            'last_received_unit_cost' => $unitCost ?? $attributes['unit_cost'],
        ]);
    }
}
