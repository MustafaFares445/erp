<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundLine;
use App\Models\PurchaseOrderLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseInboundLine>
 */
final class PurchaseInboundLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'purchase_inbound_id' => PurchaseInbound::factory(),
            'purchase_order_line_id' => PurchaseOrderLine::factory(),
        ];
    }
}
