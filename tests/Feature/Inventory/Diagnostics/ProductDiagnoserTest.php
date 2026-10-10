<?php

declare(strict_types=1);

use App\Enums\InventoryDiagnosticSeverity;
use App\Enums\InventoryPermission;
use App\Enums\StockCondition;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Inventory\Diagnostics\ProductDiagnoser;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
    $manager = User::factory()->admin()->create();
    $manager->givePermissionTo(array_map(static fn (InventoryPermission $permission): string => $permission->value, InventoryPermission::cases()));
    $this->actingAs($manager);
});

it('warns that a product without variants cannot hold stock', function (): void {
    $product = Product::factory()->create();
    ProductVariant::query()->where('product_id', $product->getKey())->delete();

    $diagnosis = app(ProductDiagnoser::class)->diagnose($product);

    expect($diagnosis->codes())->toBe(['product.no_variants'])
        ->and($diagnosis->severity())->toBe(InventoryDiagnosticSeverity::Warning);
});

it('explains on-hand stock that is fully held and restricted stock', function (): void {
    $variant = ProductVariant::factory()->create(['is_active' => true]);
    $stock = InventoryStock::factory()->for($variant, 'productVariant')->create([
        'on_hand_quantity' => 6, 'reserved_quantity' => 4, 'available_quantity' => 0,
    ]);
    InventoryConditionBalance::query()->forceCreate([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $stock->warehouse_id,
        'stock_condition' => StockCondition::Quarantine->value,
        'on_hand_base_quantity' => 2,
        'reserved_base_quantity' => 0,
    ]);

    $diagnosis = app(ProductDiagnoser::class)->diagnose($variant->product);

    expect($diagnosis->codes())->toContain('product.none_available', 'product.restricted')
        ->not->toContain('product.no_stock');
});

it('notes inactive variants and products with no stock anywhere', function (): void {
    $variant = ProductVariant::factory()->create(['is_active' => false]);

    $diagnosis = app(ProductDiagnoser::class)->diagnose($variant->product);

    expect($diagnosis->codes())->toContain('product.inactive_variants', 'product.no_stock')
        ->and($diagnosis->severity())->toBe(InventoryDiagnosticSeverity::Notice);
});

it('renders stock health, key figures and the variant summary on the product page', function (): void {
    $variant = ProductVariant::factory()->create(['is_active' => true]);
    InventoryStock::factory()->for($variant, 'productVariant')->create([
        'on_hand_quantity' => 12, 'reserved_quantity' => 2, 'available_quantity' => 10,
    ]);

    Livewire::test(ViewProduct::class, ['record' => $variant->product_id])
        ->assertOk()
        ->assertSee(__('inventory_view_ux.title'))
        ->assertSee(__('inventory_view_ux.ui.variants_and_tracking'))
        ->assertSee($variant->sku);
});
