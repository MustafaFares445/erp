<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Resources\InventoryReservations\InventoryReservationResource;
use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Resources\Products\Pages\ManageProductVendors;
use App\Filament\Resources\Warehouses\WarehouseResource;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\Package;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('catalog and warehouse viewers cannot create packages or warehouses or manually create reservations', function (): void {
    (new InventoryPermissionSeeder)->run();
    $viewer = User::factory()->create();
    $viewer->givePermissionTo([
        InventoryPermission::WarehouseView->value,
        InventoryPermission::PackageView->value,
        InventoryPermission::ReservationView->value,
    ]);
    $this->actingAs($viewer);
    expect(WarehouseResource::canViewAny())->toBeTrue()
        ->and(WarehouseResource::canCreate())->toBeFalse()
        ->and(PackageResource::canViewAny())->toBeTrue()
        ->and(PackageResource::canCreate())->toBeFalse()
        ->and(InventoryReservationResource::canCreate())->toBeFalse();
});

test('assigning a package to a draft receipt line preserves stock and does not create a movement', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $stock = InventoryStock::factory()->for($warehouse)->for($variant)->create([
        'on_hand_quantity' => '17.000000', 'reserved_quantity' => '2.000000',
        'damaged_quantity' => '0.000000', 'available_quantity' => '15.000000',
    ]);
    $operation = InventoryOperation::factory()->receipt()->create(['destination_warehouse_id' => $warehouse->id]);
    $line = $operation->lines()->create([
        'product_variant_id' => $variant->id,
        'quantity' => '3.000000',
        'unit_id' => $variant->unit_id,
    ]);
    $package = Package::factory()->for($warehouse)->create();
    $before = [$stock->on_hand_quantity, $stock->reserved_quantity, $stock->available_quantity];
    $line->update(['package_id' => $package->id]);
    $stock->refresh();
    expect($line->refresh()->package_id)->toBe($package->id)
        ->and([$stock->on_hand_quantity, $stock->reserved_quantity, $stock->available_quantity])->toBe($before)
        ->and($operation->movements()->count())->toBe(0);
});

test('Arabic supplier reference tab has a localized relation title', function (): void {
    app()->setLocale('ar');
    expect(ManageProductVendors::getRelationshipTitle())
        ->toBe(__('admin.resources.supplier_product_references'));
});

test('a real package fixture exposes view and edit only to actors with the proper permissions', function (): void {
    (new InventoryPermissionSeeder)->run();
    $package = Package::factory()->create(['name' => 'QA Protected Package']);

    $viewer = User::factory()->create();
    $viewer->givePermissionTo(InventoryPermission::PackageView->value);
    $this->actingAs($viewer);
    expect(PackageResource::canView($package))->toBeTrue()
        ->and(PackageResource::canEdit($package))->toBeFalse();
    $this->get(PackageResource::getUrl('view', ['record' => $package]))->assertOk();
    $this->get(PackageResource::getUrl('edit', ['record' => $package]))->assertForbidden();

    $manager = User::factory()->create();
    $manager->givePermissionTo([InventoryPermission::PackageView->value, InventoryPermission::PackageManage->value]);
    $this->actingAs($manager);
    expect(PackageResource::canView($package))->toBeTrue()
        ->and(PackageResource::canEdit($package))->toBeTrue();
    $this->get(PackageResource::getUrl('edit', ['record' => $package]))->assertOk();
});
