<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Resources\ProductVariants\Pages\ManageProductVariants;
use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Inventory\ProductMediaSynchronizer;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Actions\Testing\TestAction;
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

test('a variant without images uses its parent product main image', function (): void {
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create();
    $path = UploadedFile::fake()->image('parent.png')->store('product-images', 'public');

    app(ProductMediaSynchronizer::class)->sync($product, [$path]);

    expect($variant->mainImageUrl())->toBe($product->mainImageUrl());
});

test('a variant with its own image does not fall back to the parent product', function (): void {
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create();
    $path = UploadedFile::fake()->image('variant.png')->store('product-images', 'public');

    app(ProductMediaSynchronizer::class)->sync($variant, [$path]);

    expect($variant->mainImageUrl())
        ->not->toBeNull()
        ->toBe($variant->getFirstMediaUrl('images', 'thumb'));
});

test('the variant image field authorizes only paths belonging to the record media', function (): void {
    $variant = ProductVariant::factory()->create();
    $path = UploadedFile::fake()->image('variant-gallery.png')->store('product-images', 'public');
    app(ProductMediaSynchronizer::class)->sync($variant, [$path]);
    $media = $variant->fresh()->getFirstMedia('images');

    $allowFilePathUsing = productVariantAllowFilePathUsingClosure();

    expect($allowFilePathUsing(null, $path))->toBeFalse()
        ->and($allowFilePathUsing($variant, 'unknown/path.png'))->toBeFalse()
        ->and($allowFilePathUsing($variant, $media->getPathRelativeToRoot()))->toBeTrue();
});

test('the product variant edit action hydrates the images field from existing media', function (): void {
    (new InventoryPermissionSeeder)->run();
    $manager = User::factory()->admin()->create();
    $manager->givePermissionTo([
        InventoryPermission::CatalogView->value,
        InventoryPermission::CatalogManage->value,
    ]);
    $variant = ProductVariant::factory()->create();
    $path = UploadedFile::fake()->image('variant-hydrated.png')->store('product-images', 'public');
    app(ProductMediaSynchronizer::class)->sync($variant, [$path]);
    $variant->refresh();

    Livewire::actingAs($manager)
        ->test(ManageProductVariants::class)
        ->mountAction(TestAction::make('edit')->table($variant))
        ->assertActionDataSet(['images' => [$variant->getFirstMedia('images')->getPathRelativeToRoot()]]);
});

function productVariantAllowFilePathUsingClosure(): Closure
{
    $schema = ProductVariantResource::form(Schema::make());
    /** @var FileUpload $component */
    $component = collect($schema->getComponents())->sole(fn (mixed $candidate): bool => $candidate instanceof FileUpload && $candidate->getName() === 'images');

    $property = new ReflectionProperty($component, 'allowFilePathUsing');

    return $property->getValue($component);
}
