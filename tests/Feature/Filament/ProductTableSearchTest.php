<?php

declare(strict_types=1);

use App\Filament\Resources\Products\Pages\ManageProducts;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
    $this->actor = User::factory()->create();
});

it('searches the products table by variant sku and barcode', function (): void {
    $skuProduct = Product::factory()->create(['name' => 'SKU search product']);
    ProductVariant::factory()->create([
        'product_id' => $skuProduct->getKey(),
        'sku' => 'WP42-SKU-001',
        'barcode' => '1111111111111',
    ]);

    $barcodeProduct = Product::factory()->create(['name' => 'Barcode search product']);
    ProductVariant::factory()->create([
        'product_id' => $barcodeProduct->getKey(),
        'sku' => 'WP42-SKU-002',
        'barcode' => '9876543210123',
    ]);

    $unrelatedProduct = Product::factory()->create(['name' => 'Unrelated product']);
    ProductVariant::factory()->create([
        'product_id' => $unrelatedProduct->getKey(),
        'sku' => 'UNRELATED-SKU',
        'barcode' => '2222222222222',
    ]);

    Livewire::actingAs($this->actor)
        ->test(ManageProducts::class)
        ->searchTable('WP42-SKU-001')
        ->assertCanSeeTableRecords([$skuProduct])
        ->assertCanNotSeeTableRecords([$barcodeProduct, $unrelatedProduct]);

    Livewire::actingAs($this->actor)
        ->test(ManageProducts::class)
        ->searchTable('9876543210123')
        ->assertCanSeeTableRecords([$barcodeProduct])
        ->assertCanNotSeeTableRecords([$skuProduct, $unrelatedProduct]);
});
