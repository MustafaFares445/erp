<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Observers\ProductVariantObserver;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
final class ProductVariantFactory extends Factory
{
    #[\Override]
    public function configure(): static
    {
        return $this->afterCreating(function (ProductVariant $variant): void {
            if ($variant->product instanceof Product && $variant->unit instanceof Unit) {
                $variant->product->addAllowedUnit($variant->unit);

                $variant->variantUnits()->firstOrCreate(
                    ['unit_id' => $variant->unit->getKey()],
                    [
                        'is_base' => true,
                        'is_purchase' => true,
                        'is_sale' => true,
                        'is_display' => true,
                        'factor_to_base' => '1.000000',
                        'rounding_increment' => $variant->unit->precision === 0 ? '1.000000' : '0.001000',
                        'permits_cross_family_conversion' => false,
                        'is_active' => true,
                        'effective_from' => now(),
                    ],
                );
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'sku' => mb_strtoupper(fake()->unique()->bothify('SKU-####??')),
            'name' => fake()->words(2, true),
            'unit_id' => Unit::factory(),
            'is_active' => true,
        ];
    }

    /**
     * The tracking flags are deliberately not set by these states — the parent product's type
     * drives them, and {@see ProductVariantObserver} applies them on save. Each
     * state therefore sets the *product* type, which is the single source of truth, plus
     * whatever else that type requires to be a complete record.
     */
    public function machine(): self
    {
        return $this->state([
            'product_id' => Product::factory()->machine(),
            'unit_id' => Unit::factory()->whole(),
        ]);
    }

    public function expiryMaterial(): self
    {
        return $this->state(['product_id' => Product::factory()->expiryMaterial()]);
    }

    public function grain(): self
    {
        return $this->state([
            'product_id' => Product::factory()->grain(),
            'net_weight' => fake()->randomFloat(3, 0.5, 50),
            'weight_unit_id' => Unit::factory()->weight(),
        ]);
    }
}
