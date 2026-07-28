<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Inventory\ProductMediaSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

test('a variant without images uses its parent product main image', function (): void {
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create();
    $path = UploadedFile::fake()->image('parent.png')->store('product-images', 'public');

    app(ProductMediaSynchronizer::class)->sync($product, [$path]);

    expect($variant->mainImageUrl())->toBe($product->mainImageUrl());
});
