<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\QuotationLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuotationLine>
 */
final class QuotationLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->randomFloat(3, 1, 10);
        $unitPrice = fake()->randomFloat(2, 10, 500);
        $lineTotal = round($quantity * $unitPrice, 2);

        return [
            'quotation_id' => Quotation::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tax_amount' => '0.00',
            'line_total' => $lineTotal,
            'sort_order' => 0,
        ];
    }
}
