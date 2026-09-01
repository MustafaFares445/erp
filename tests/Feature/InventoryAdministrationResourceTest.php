<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\MovementType;
use App\Filament\Pages\CatalogSetup;
use App\Filament\Resources\InventorySettings\InventorySettingResource;
use App\Filament\Resources\InventorySettings\Pages\ManageInventorySettings;
use App\Filament\Resources\Returns\ReturnResource;
use App\Filament\Resources\SerializedInventoryUnits\SerializedInventoryUnitResource;
use App\Models\InventoryMovement;
use App\Models\InventorySetting;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

it('keeps retired receipt administration out of the Filament surface', function (): void {
    expect(class_exists('App\\Filament\\Resources\\InventoryReceipts\\InventoryReceiptResource'))->toBeFalse();
});

it('creates the singleton inventory setting and then disables further creation', function (): void {
    $manager = inventoryAdministrator([
        InventoryPermission::PricingView,
        InventoryPermission::PricingManage,
    ]);

    expect(InventorySettingResource::canCreate())->toBeFalse();

    $this->actingAs($manager);

    expect(InventorySettingResource::canCreate())->toBeTrue();

    Livewire::actingAs($manager)
        ->test(ManageInventorySettings::class)
        ->callAction(TestAction::make('create'), [
            'default_markup_percent' => 20,
            'expiry_alert_days' => 45,
        ])
        ->assertHasNoActionErrors();

    expect(InventorySetting::query()->sole()->expiry_alert_days)->toBe(45)
        ->and(InventorySettingResource::canCreate())->toBeFalse();
});

it('keeps retired reservation administration out of the Filament surface', function (): void {
    expect(class_exists('App\\Filament\\Resources\\StockReservations\\StockReservationResource'))->toBeFalse();
});

it('redirects returns to the filtered movement log while keeping the query scoped', function (): void {
    $viewer = inventoryAdministrator([InventoryPermission::MovementView, InventoryPermission::ReturnView]);
    $return = InventoryMovement::factory()->create(['movement_type' => MovementType::Return]);
    $receipt = InventoryMovement::factory()->create(['movement_type' => MovementType::Receipt]);

    $this->actingAs($viewer)
        ->get(ReturnResource::getUrl('index'))
        ->assertOk();

    expect(ReturnResource::getNavigationLabel())->toBe(__('admin.resources.returns'));
});

it('denies inventory administration resources without their source permissions', function (): void {
    $administrator = User::factory()->admin()->create();

    $this->actingAs($administrator);

    expect(class_exists('App\\Filament\\Resources\\InventoryReceipts\\InventoryReceiptResource'))->toBeFalse();
    $this->get(ReturnResource::getUrl('index'))->assertForbidden();
});

it('builds read-only inventory forms and rejects unauthenticated action actors', function (): void {
    auth()->logout();

    expect(CatalogSetup::getNavigationLabel())->toBe(__('admin.resources.catalog_setup'))
        ->and(SerializedInventoryUnitResource::getGlobalSearchResultDetails(new Warehouse))->toBe([]);
});

/** @param list<InventoryPermission> $permissions */
function inventoryAdministrator(array $permissions): User
{
    $manager = User::factory()->admin()->create();
    $manager->givePermissionTo(array_map(
        static fn (InventoryPermission $permission): string => $permission->value,
        $permissions,
    ));

    return $manager;
}
