<?php

declare(strict_types=1);

use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds a legacy variant link to its parent product variants tab', function (): void {
    $variant = ProductVariant::factory()->create();

    expect(ProductVariantResource::parentProductVariantsUrl($variant))
        ->toContain('/products/'.$variant->product_id.'/variants');
});
