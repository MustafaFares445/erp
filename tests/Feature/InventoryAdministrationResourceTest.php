<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\MovementType;
use App\Filament\Pages\CatalogSetup;
use App\Filament\Resources\InventoryReceipts\InventoryReceiptResource;
use App\Filament\Resources\InventoryReceipts\Pages\ManageInventoryReceipts;
use App\Filament\Resources\InventorySettings\InventorySettingResource;
use App\Filament\Resources\InventorySettings\Pages\ManageInventorySettings;
use App\Filament\Resources\Returns\Pages\ManageReturns;
use App\Filament\Resources\Returns\ReturnResource;
use App\Filament\Resources\SerializedInventoryUnits\SerializedInventoryUnitResource;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\StockReservations\Pages\ManageStockReservations;
use App\Filament\Resources\Transfers\Pages\ViewTransfer;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptItem;
use App\Models\InventorySetting;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

it('confirms a draft receipt through its authorized Filament action', function (): void {
    $manager = inventoryAdministrator([
        InventoryPermission::ReceiptView,
        InventoryPermission::ReceiptCreate,
        InventoryPermission::ReceiptConfirm,
    ]);
    $receipt = InventoryReceipt::factory()->create();
    InventoryReceiptItem::factory()->for($receipt, 'receipt')->create();

    Livewire::actingAs($manager)
        ->test(ManageInventoryReceipts::class)
        ->assertActionVisible(TestAction::make('confirm')->table($receipt))
        ->callAction(TestAction::make('confirm')->table($receipt))
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($receipt->fresh()->isDraft())->toBeFalse()
        ->and(InventoryReceiptResource::getRecordRouteBindingEloquentQuery()->find($receipt->getKey()))->toBeInstanceOf(InventoryReceipt::class);
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

it('redirects reservation administration to the reserved stock filter', function (): void {
    $viewer = inventoryAdministrator([InventoryPermission::ReservationView]);

    Livewire::actingAs($viewer)
        ->test(ManageStockReservations::class)
        ->assertRedirect(StockLevelResource::getUrl('index', [
            'tableFilters' => ['reserved' => ['isActive' => true]],
        ]));
});

it('redirects returns to the filtered movement log while keeping the query scoped', function (): void {
    $viewer = inventoryAdministrator([InventoryPermission::MovementView]);
    $return = InventoryMovement::factory()->create(['movement_type' => MovementType::Return]);
    $receipt = InventoryMovement::factory()->create(['movement_type' => MovementType::Receipt]);

    Livewire::actingAs($viewer)
        ->test(ManageReturns::class)
        ->assertRedirect(StockMovementResource::getUrl('index', [
            'tableFilters' => ['movement_type' => ['value' => MovementType::Return->value]],
        ]));

    expect(ReturnResource::getNavigationLabel())->toBe(__('admin.resources.returns'))
        ->and(ReturnResource::getEloquentQuery()->pluck('id')->all())->toBe([$return->getKey()]);
});

it('denies inventory administration resources without their source permissions', function (): void {
    $administrator = User::factory()->admin()->create();

    $this->actingAs($administrator);

    $this->get(InventoryReceiptResource::getUrl('index'))->assertForbidden();
    $this->get(ReturnResource::getUrl('index'))->assertForbidden();
});

it('builds read-only inventory forms and rejects unauthenticated action actors', function (): void {
    auth()->logout();

    $receiptActor = new ReflectionMethod(InventoryReceiptResource::class, 'actor');
    $transferActor = new ReflectionMethod(ViewTransfer::class, 'actor');

    expect(ReturnResource::form(Schema::make())->getComponents())->toBe([])
        ->and(CatalogSetup::getNavigationLabel())->toBe(__('admin.resources.catalog_setup'))
        ->and(SerializedInventoryUnitResource::getGlobalSearchResultDetails(new Warehouse))->toBe([])
        ->and(fn (): mixed => $receiptActor->invoke(null))->toThrow(LogicException::class)
        ->and(fn (): mixed => $transferActor->invoke(null))->toThrow(LogicException::class);
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
