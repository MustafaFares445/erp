<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Services\Inventory\QuantityNormalizer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuotationLine>
 */
final class QuotationLineFactory extends Factory
{
    #[\Override]
    public function configure(): static
    {
        return $this->afterCreating(function (QuotationLine $line): void {
            $variant = $line->productVariant;

            if (! $variant instanceof ProductVariant || ! is_int($line->unit_id)) {
                return;
            }

            $snapshot = app(QuantityNormalizer::class)->normalize(
                $variant,
                $line->unit_id,
                (string) $line->quantity,
            );

            $line->forceFill([
                'transaction_quantity' => $snapshot->transactionQuantity,
                'transaction_unit_id' => $snapshot->transactionUnitId,
                'conversion_factor_snapshot' => $snapshot->conversionFactorSnapshot,
                'base_quantity' => $snapshot->baseQuantity,
            ])->saveQuietly();
        });
    }

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
            'unit_id' => static fn (array $attributes): int => (int) ProductVariant::query()->findOrFail($attributes['product_variant_id'])->unit_id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tax_amount' => '0.00',
            'line_total' => $lineTotal,
            'sort_order' => 0,
        ];
    }
}
