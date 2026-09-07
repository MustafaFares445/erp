<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceFloorOverride;
use App\Models\PricingTier;
use App\Models\ProductVariant;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves its order, product variant, and unit relations and casts quantity as a decimal', function (): void {
    $order = Order::factory()->create();
    $unit = Unit::factory()->create();
    $variant = ProductVariant::factory()->create(['unit_id' => $unit->getKey()]);

    $line = OrderLine::factory()->create([
        'order_id' => $order->getKey(),
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity' => '3.500',
    ]);

    expect($line->order()->first()->is($order))->toBeTrue()
        ->and($line->productVariant()->first()->is($variant))->toBeTrue()
        ->and($line->unit()->first()->is($unit))->toBeTrue()
        ->and($line->quantity)->toBe('3.500000');
});

it('resolves its price provenance relations', function (): void {
    $tier = PricingTier::factory()->create();
    $override = PriceFloorOverride::factory()->create();

    $line = new OrderLine([
        'resolved_price_tier_id' => $tier->getKey(),
        'price_floor_override_id' => $override->getKey(),
    ]);

    expect($line->resolvedPriceTier()->first()->is($tier))->toBeTrue()
        ->and($line->priceFloorOverride()->first()->is($override))->toBeTrue();
});
