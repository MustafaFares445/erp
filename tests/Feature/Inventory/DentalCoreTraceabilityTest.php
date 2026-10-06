<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\ProductOperationalProfile;
use App\Enums\TrackingMode;
use App\Models\Brand;
use App\Models\InventoryLot;
use App\Models\InventorySetting;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryLotService;
use App\Services\Inventory\ProductVariantUomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('keeps manufacturer and commercial brand separate while inheriting a brand manufacturer', function (): void {
    $manufacturer = Manufacturer::factory()->create();
    $brand = Brand::factory()->create(['manufacturer_id' => $manufacturer->getKey()]);

    $product = Product::factory()->create([
        'manufacturer_id' => null,
        'brand_id' => $brand->getKey(),
        'operational_profile' => ProductOperationalProfile::Standard,
    ]);

    expect($product->refresh()->manufacturer_id)->toBe($manufacturer->getKey())
        ->and($product->brand?->getKey())->toBe($brand->getKey());
});

it('supports explicit untracked variants independently of the legacy product classification', function (): void {
    $product = Product::factory()->create([
        'operational_profile' => ProductOperationalProfile::Standard,
    ]);

    $variant = ProductVariant::factory()->for($product)->create([
        'tracking_mode' => TrackingMode::None,
        'tracks_expiration' => false,
    ]);

    expect($variant->refresh()->trackingMode())->toBe(TrackingMode::None)
        ->and($variant->track_serials)->toBeFalse()
        ->and($variant->track_batches)->toBeFalse()
        ->and($variant->track_expiry)->toBeFalse();
});

it('stores packaging metadata on a variant UOM without creating a fake variant', function (): void {
    $piece = Unit::factory()->whole()->create([
        'code' => 'TRACE-PIECE',
        'family' => 'count',
        'name' => 'Piece',
        'symbol' => 'pc',
    ]);
    $box = Unit::factory()->whole()->create([
        'code' => 'TRACE-BOX',
        'family' => 'count',
        'name' => 'Box',
        'symbol' => 'box',
    ]);
    $variant = ProductVariant::factory()->create();

    app(ProductVariantUomService::class)->sync($variant, [
        [
            'unit_id' => $piece->getKey(),
            'packaging_name' => null,
            'barcode' => null,
            'is_base' => true,
            'is_purchase' => false,
            'is_sale' => true,
            'is_display' => true,
            'factor_to_base' => '1',
            'rounding_increment' => '1',
            'permits_cross_family_conversion' => false,
            'is_active' => true,
        ],
        [
            'unit_id' => $box->getKey(),
            'packaging_name' => 'Box of 100',
            'barcode' => '6290000000100',
            'is_base' => false,
            'is_purchase' => true,
            'is_sale' => true,
            'is_display' => true,
            'factor_to_base' => '100',
            'rounding_increment' => '1',
            'permits_cross_family_conversion' => false,
            'is_active' => true,
        ],
    ]);

    $packaging = $variant->variantUnits()->where('unit_id', $box->getKey())->sole();

    expect($packaging->packaging_name)->toBe('Box of 100')
        ->and($packaging->barcode)->toBe('6290000000100')
        ->and($packaging->factor_to_base)->toBe('100.000000')
        ->and($variant->refresh()->getKey())->toBe($variant->getKey());
});

it('classifies expiration into configurable 30 60 90 day buckets', function (): void {
    InventorySetting::current()->update([
        'expiry_critical_days' => 30,
        'expiry_warning_days' => 60,
        'expiry_notice_days' => 90,
    ]);

    $variant = ProductVariant::factory()->expiryMaterial()->create();

    $expired = InventoryLot::factory()->canonical()->for($variant, 'productVariant')->create(['expires_at' => today()->subDay()]);
    $critical = InventoryLot::factory()->canonical()->for($variant, 'productVariant')->create(['expires_at' => today()->addDays(30)]);
    $warning = InventoryLot::factory()->canonical()->for($variant, 'productVariant')->create(['expires_at' => today()->addDays(31)]);
    $notice = InventoryLot::factory()->canonical()->for($variant, 'productVariant')->create(['expires_at' => today()->addDays(61)]);
    $healthy = InventoryLot::factory()->canonical()->for($variant, 'productVariant')->create(['expires_at' => today()->addDays(91)]);

    expect($expired->expiryBucket())->toBe('expired')
        ->and($critical->expiryBucket())->toBe('critical')
        ->and($warning->expiryBucket())->toBe('warning')
        ->and($notice->expiryBucket())->toBe('notice')
        ->and($healthy->expiryBucket())->toBe('healthy');
});

it('enforces FEFO and requires an authorized reason to choose a later lot', function (): void {
    $variant = ProductVariant::factory()->expiryMaterial()->create();
    $warehouse = Warehouse::factory()->create();

    $earliest = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'lot_number' => 'FEFO-EARLY',
        'expires_at' => today()->addDays(10),
        'on_hand_quantity' => '5.000000',
        'reserved_quantity' => '0.000000',
    ]);
    $later = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'lot_number' => 'FEFO-LATE',
        'expires_at' => today()->addDays(20),
        'on_hand_quantity' => '5.000000',
        'reserved_quantity' => '0.000000',
    ]);

    $service = app(InventoryLotService::class);
    $warehouseId = (int) $warehouse->getKey();

    expect($service->preferredFefoLot($variant, $warehouseId, '1.000000')?->getKey())
        ->toBe($earliest->getKey());

    $actor = User::factory()->create();

    expect(fn () => $service->assertFefoSelection(
        $later,
        $variant,
        $warehouseId,
        '1.000000',
        $actor,
    ))->toThrow(DomainException::class, 'FEFO requires the earliest valid expiry lot');

    Permission::findOrCreate(InventoryPermission::FefoOverride->value, 'web');
    $actor->givePermissionTo(InventoryPermission::FefoOverride->value);

    expect(fn () => $service->assertFefoSelection(
        $later,
        $variant,
        $warehouseId,
        '1.000000',
        $actor,
    ))->toThrow(DomainException::class, 'FEFO override reason');

    expect($service->assertFefoSelection(
        $later,
        $variant,
        $warehouseId,
        '1.000000',
        $actor,
        'Customer requires the later-expiry batch for a validated clinical schedule.',
    )->getKey())->toBe($later->getKey());
});
