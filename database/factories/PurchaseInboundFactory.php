<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PurchaseInboundStatus;
use App\Models\PurchaseInbound;
use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseInbound>
 */
final class PurchaseInboundFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory()->accepted(),
            'status' => PurchaseInboundStatus::AwaitingAllocation,
            'activated_at' => now(),
        ];
    }

    public function awaitingReceipt(): self
    {
        return $this->state(fn (): array => [
            'status' => PurchaseInboundStatus::AwaitingReceipt,
            'allocation_confirmed_at' => now(),
        ]);
    }
}
