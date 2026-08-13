<?php

declare(strict_types=1);

use App\Models\AiKeywordRule;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves the linked product and product variant', function (): void {
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create();

    $rule = AiKeywordRule::factory()->create([
        'product_id' => $product->getKey(),
        'product_variant_id' => $variant->getKey(),
    ]);

    expect($rule->product()->first()->is($product))->toBeTrue()
        ->and($rule->productVariant()->first()->is($variant))->toBeTrue();
});
