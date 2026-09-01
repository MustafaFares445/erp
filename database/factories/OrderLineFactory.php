<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use App\Services\Inventory\QuantityNormalizer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderLine>
 */
final class OrderLineFactory extends Factory
{
    #[\Override]
    public function configure(): static
    {
        return $this->afterCreating(function (OrderLine $line): void {
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
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'quantity' => fake()->randomFloat(3, 0.1, 50),
            'unit_id' => static fn (array $attributes): int => (int) ProductVariant::query()->findOrFail($attributes['product_variant_id'])->unit_id,
        ];
    }
}
