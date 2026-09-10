<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseInboundLine;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseInboundAllocation>
 */
final class PurchaseInboundAllocationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'purchase_inbound_line_id' => PurchaseInboundLine::factory(),
            'warehouse_id' => Warehouse::factory(),
        ];
    }
}
