<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\ProductMediaSynchronizer;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

test('media library uses imagick when it is available for image conversions', function (): void {
    expect(config('media-library.image_driver'))
        ->toBe(extension_loaded('imagick') ? 'imagick' : 'gd');
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

test('the product image field authorizes only paths belonging to the record media', function (): void {
    $product = Product::factory()->create();
    $path = productImagePath('gallery.png');
    app(ProductMediaSynchronizer::class)->sync($product, [$path]);
    $media = $product->fresh()->getFirstMedia('images');

    $allowFilePathUsing = productFormAllowFilePathUsingClosure();

    expect($allowFilePathUsing(null, $path))->toBeFalse()
        ->and($allowFilePathUsing($product, 'unknown/path.png'))->toBeFalse()
        ->and($allowFilePathUsing($product, $media->getPathRelativeToRoot()))->toBeTrue();
})->skip('Filament schema callbacks require a mounted Livewire schema host in Filament v5.');

test('the product edit page hydrates the images field from existing media', function (): void {
    (new InventoryPermissionSeeder)->run();
    $manager = User::factory()->admin()->create();
    $manager->givePermissionTo([
        InventoryPermission::CatalogView->value,
        InventoryPermission::CatalogManage->value,
    ]);
    $product = Product::factory()->create();
    $path = productImagePath('hydrated.png');
    app(ProductMediaSynchronizer::class)->sync($product, [$path]);
    $product->refresh();

    Livewire::actingAs($manager)
        ->test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertOk()
        ->assertFormSet(['images' => [$product->getFirstMedia('images')->getPathRelativeToRoot()]]);
});

test('productData keeps only fillable string keys', function (): void {
    expect(ProductForm::productData([
        0 => 'discarded because the key is not a string',
        'name' => 'Kept',
        'not_fillable_field' => 'discarded because it is not fillable',
    ]))->toBe(['name' => 'Kept']);
});

function productImagePath(string $name): string
{
    return UploadedFile::fake()->image($name)->store('product-images', 'public');
}

function productFormAllowFilePathUsingClosure(): Closure
{
    $schema = ProductForm::configure(Schema::make());
    /** @var FileUpload $component */
    $component = collect($schema->getComponents())->sole(fn (mixed $candidate): bool => $candidate instanceof FileUpload && $candidate->getName() === 'images');

    $property = new ReflectionProperty($component, 'allowFilePathUsing');

    return $property->getValue($component);
}
