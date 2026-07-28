<?php

declare(strict_types=1);

use App\Models\Product;
use App\Services\Inventory\ProductMediaSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

test('the first product image is the main list image', function (): void {
    $product = Product::factory()->create();
    $firstImage = productImagePath('first.png');
    $secondImage = productImagePath('second.png');

    app(ProductMediaSynchronizer::class)->sync($product, [$firstImage, $secondImage]);

    $product->refresh();

    $media = $product->getMedia('images');

    expect($media)->toHaveCount(2)
        ->and($media->first()?->order_column)->toBe(1)
        ->and($product->mainImageUrl())->toBe($product->getFirstMediaUrl('images', 'thumb'));
});

function productImagePath(string $name): string
{
    return UploadedFile::fake()->image($name)->store('product-images', 'public');
}
