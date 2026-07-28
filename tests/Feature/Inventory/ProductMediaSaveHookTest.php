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

test('the product media save hook adds images', function (): void {
    $product = Product::factory()->create();
    $firstImage = productMediaSaveHookImagePath('first.png');
    $secondImage = productMediaSaveHookImagePath('second.png');

    app(ProductMediaSynchronizer::class)->sync($product, [$firstImage, $secondImage]);

    expect($product->getMedia('images'))->toHaveCount(2);
});

test('the product media save hook reorders images', function (): void {
    $product = Product::factory()->create();
    $firstImage = productMediaSaveHookImagePath('first.png');
    $secondImage = productMediaSaveHookImagePath('second.png');
    $synchronizer = app(ProductMediaSynchronizer::class);
    $synchronizer->sync($product, [$firstImage, $secondImage]);

    $media = $product->getMedia('images');
    $firstId = $media[0]->getKey();
    $secondId = $media[1]->getKey();

    $synchronizer->sync($product, [
        $media[1]->getPathRelativeToRoot(),
        $media[0]->getPathRelativeToRoot(),
    ]);

    expect($product->getMedia('images')->modelKeys())->toBe([$secondId, $firstId]);
});

test('the product media save hook removes images', function (): void {
    $product = Product::factory()->create();
    $firstImage = productMediaSaveHookImagePath('first.png');
    $secondImage = productMediaSaveHookImagePath('second.png');
    $synchronizer = app(ProductMediaSynchronizer::class);
    $synchronizer->sync($product, [$firstImage, $secondImage]);

    $secondId = $product->getMedia('images')[1]->getKey();

    $synchronizer->sync($product, [$product->getMedia('images')[1]->getPathRelativeToRoot()]);

    expect($product->getMedia('images')->modelKeys())->toBe([$secondId]);
});

test('the product media save hook leaves an unchanged collection untouched', function (): void {
    $product = Product::factory()->create();
    $firstImage = productMediaSaveHookImagePath('first.png');
    $secondImage = productMediaSaveHookImagePath('second.png');
    $synchronizer = app(ProductMediaSynchronizer::class);
    $synchronizer->sync($product, [$firstImage, $secondImage]);

    $before = $product->getMedia('images')->map(fn ($media): array => [$media->id, $media->order_column])->all();

    $synchronizer->sync($product, $product->getMedia('images')->map->getPathRelativeToRoot()->all());

    expect($product->getMedia('images')->map(fn ($media): array => [$media->id, $media->order_column])->all())->toBe($before);
});

function productMediaSaveHookImagePath(string $name): string
{
    return UploadedFile::fake()->image($name)->store('product-images', 'public');
}
