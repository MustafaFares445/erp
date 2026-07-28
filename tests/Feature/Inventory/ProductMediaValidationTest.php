<?php

declare(strict_types=1);

use App\Models\Product;
use App\Services\Inventory\ProductMediaSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

test('an unsupported image mime type is rejected without changing existing images', function (): void {
    $product = Product::factory()->create();
    $synchronizer = app(ProductMediaSynchronizer::class);
    $image = productMediaValidationImagePath('existing.png');
    $synchronizer->sync($product, [$image]);
    Storage::disk('public')->put('product-images/unsupported.txt', 'not an image');

    try {
        $synchronizer->sync($product, [
            $product->getFirstMedia('images')->getPathRelativeToRoot(),
            'product-images/unsupported.txt',
        ]);
    } catch (ValidationException $validationException) {
        expect($validationException->errors()['images'][0])->toContain('JPEG, PNG, or WebP');
    }

    expect(Product::query()->firstOrFail()->getMedia('images'))->toHaveCount(1);
});

test('an oversized image is rejected without changing existing images', function (): void {
    $product = Product::factory()->create();
    $synchronizer = app(ProductMediaSynchronizer::class);
    $image = productMediaValidationImagePath('existing.png');
    $synchronizer->sync($product, [$image]);
    $largeImage = 'product-images/large.png';
    Storage::disk('public')->put($largeImage, str_repeat('a', 5 * 1024 * 1024 + 1));

    try {
        $synchronizer->sync($product, [
            $product->getFirstMedia('images')->getPathRelativeToRoot(),
            $largeImage,
        ]);
    } catch (ValidationException $validationException) {
        expect($validationException->errors()['images'][0])->toContain('greater than 5 MB');
    }

    expect(Product::query()->firstOrFail()->getMedia('images'))->toHaveCount(1);
});

function productMediaValidationImagePath(string $name): string
{
    return UploadedFile::fake()->image($name)->store('product-images', 'public');
}
