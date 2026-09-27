<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Schemas\PurchaseOrderForm;
use App\Models\ProductVariant;
use App\Models\ProductVariantUnit;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\Unit;
use App\Models\User;
use App\Services\Purchasing\PurchaseOrderService;
use Database\Seeders\PurchasePermissionSeeder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('covers invalid purchase-order variant and unit reactive states', function (): void {
    (new PurchasePermissionSeeder)->run();
    $actor = User::factory()->admin()->create();
    $actor->assignRole(DashboardRole::PurchasingManager->value);

    $test = Livewire::actingAs($actor)->test(CreatePurchaseOrder::class);
    $schema = $test->instance()->getSchema('form');
    $components = collect($schema->getFlatComponents(withHidden: true));
    $lines = $components->first(
        static fn (mixed $component): bool => $component instanceof Repeater && $component->getName() === 'lines',
    );

    expect($lines)->toBeInstanceOf(Repeater::class);

    $lineComponents = collect($lines->getChildSchema()?->getFlatComponents(withHidden: true) ?? []);

    $variantSelect = $lineComponents->first(
        static fn (mixed $component): bool => $component instanceof Select && $component->getName() === 'product_variant_id',
    );
    $unitSelect = $lineComponents->first(
        static fn (mixed $component): bool => $component instanceof Select && $component->getName() === 'unit_id',
    );

    expect($variantSelect)->toBeInstanceOf(Select::class)
        ->and($unitSelect)->toBeInstanceOf(Select::class);

    $afterStateUpdated = new ReflectionProperty($variantSelect, 'afterStateUpdated');
    /** @var array<int, Closure> $variantCallbacks */
    $variantCallbacks = $afterStateUpdated->getValue($variantSelect);
    /** @var array<int, Closure> $unitCallbacks */
    $unitCallbacks = $afterStateUpdated->getValue($unitSelect);

    $set = Mockery::mock(Set::class);
    foreach (['unit_id', 'unit_cost', 'brand', 'supplier_product_name', 'supplier_item_number_preview', 'reference_currency'] as $field) {
        $set->shouldReceive('__invoke')->once()->with($field, null);
    }
    $get = Mockery::mock(Get::class);
    $get->shouldReceive('__invoke')->never();

    $variantCallbacks[0]($get, $set, 'not-numeric');

    $unitSet = Mockery::mock(Set::class);
    $unitSet->shouldReceive('__invoke')->never();
    $unitGet = Mockery::mock(Get::class);
    $unitGet->shouldReceive('__invoke')->never();

    $unitCallbacks[0]($unitGet, $unitSet, 'not-numeric');
});

it('defaults supplier cost only through an active purchase-unit conversion in the same currency', function (): void {
    $supplier = Supplier::factory()->create();
    $variant = ProductVariant::factory()->create();
    $purchaseUnit = Unit::factory()->create();

    SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'currency_code' => 'AED',
        'purchase_cost' => '7.50',
    ]);
    ProductVariantUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $purchaseUnit->getKey(),
        'is_purchase' => true,
        'is_active' => true,
        'factor_to_base' => '4.000000',
    ]);

    $method = new ReflectionMethod(PurchaseOrderForm::class, 'defaultUnitCost');

    expect($method->invoke(
        null,
        $supplier->getKey(),
        $variant->getKey(),
        $purchaseUnit->getKey(),
        'AED',
    ))->toBe(30.0);

    ProductVariantUnit::query()
        ->where('product_variant_id', $variant->getKey())
        ->where('unit_id', $purchaseUnit->getKey())
        ->update(['is_purchase' => false]);

    expect($method->invoke(
        null,
        $supplier->getKey(),
        $variant->getKey(),
        $purchaseUnit->getKey(),
        'AED',
    ))->toBeNull();
});

it('returns no default cost when no supplier reference exists', function (): void {
    $supplier = Supplier::factory()->create();
    $variant = ProductVariant::factory()->create();

    $method = new ReflectionMethod(PurchaseOrderForm::class, 'defaultUnitCost');

    expect($method->invoke(
        null,
        $supplier->getKey(),
        $variant->getKey(),
        $variant->unit_id,
        'AED',
    ))->toBeNull()
        ->and(app(PurchaseOrderService::class)->referenceFor(
            $supplier->getKey(),
            $variant->getKey(),
        ))->toBeNull();
});
