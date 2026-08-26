<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bill;
use App\Models\BillLine;
use App\Models\ChartAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BillLine> */
final class BillLineFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $quantity = fake()->randomFloat(3, 1, 10);
        $unitPrice = fake()->randomFloat(2, 10, 500);
        $tax = fake()->randomFloat(2, 0, 50);

        return [
            'bill_id' => Bill::factory(),
            'purchase_order_line_id' => null,
            'product_variant_id' => null,
            'chart_account_id' => ChartAccount::factory(),
            'description' => fake()->sentence(),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tax_amount' => $tax,
            'line_total' => round($quantity * $unitPrice, 2),
            'sort_order' => 1,
        ];
    }
}
