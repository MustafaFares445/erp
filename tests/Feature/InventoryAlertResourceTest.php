<?php

declare(strict_types=1);

use App\Enums\InventoryAlertSeverity;
use App\Enums\InventoryAlertType;
use App\Enums\InventoryPermission;
use App\Filament\Resources\InventoryAlerts\InventoryAlertResource;
use App\Filament\Resources\InventoryAlerts\Pages\ListInventoryAlerts;
use App\Models\InventoryAlert;
use App\Models\InventoryStock;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Actions\ViewAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

it('lists and filters active and resolved alerts by type and severity', function (): void {
    $viewer = alertViewer();
    $stock = InventoryStock::factory()->create();
    $active = InventoryAlert::factory()->create([
        'type' => InventoryAlertType::OutOfStock,
        'subject_type' => InventoryStock::class,
        'subject_id' => $stock->getKey(),
        'severity' => InventoryAlertSeverity::Critical,
    ]);
    $resolved = InventoryAlert::factory()->create([
        'type' => InventoryAlertType::LowStock,
        'severity' => InventoryAlertSeverity::Warning,
        'resolved_at' => now(),
    ]);

    Livewire::actingAs($viewer)
        ->test(ListInventoryAlerts::class)
        ->filterTable('active', true)
        ->filterTable('type', InventoryAlertType::OutOfStock->value)
        ->filterTable('severity', InventoryAlertSeverity::Critical->value)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$resolved]);

    Livewire::actingAs($viewer)
        ->test(ListInventoryAlerts::class)
        ->filterTable('active', false)
        ->assertCanSeeTableRecords([$resolved])
        ->assertCanNotSeeTableRecords([$active]);
});

it('shows an originating-record link only when its source permission is available', function (): void {
    $viewer = alertViewer(withStockView: true);
    $stock = InventoryStock::factory()->create();
    $alert = InventoryAlert::factory()->create([
        'subject_type' => InventoryStock::class,
        'subject_id' => $stock->getKey(),
    ]);

    Livewire::actingAs($viewer)
        ->test(ListInventoryAlerts::class)
        ->assertActionVisible(TestAction::make('open_origin')->table($alert));

    $alertOnlyViewer = alertViewer();

    Livewire::actingAs($alertOnlyViewer)
        ->test(ListInventoryAlerts::class)
        ->assertActionHidden(TestAction::make('open_origin')->table($alert));
});

it('keeps alerts read only and denies users without alert view', function (): void {
    $viewer = alertViewer();
    $alert = InventoryAlert::factory()->create();
    $component = Livewire::actingAs($viewer)
        ->test(ListInventoryAlerts::class)
        ->assertCanSeeTableRecords([$alert]);
    $actions = $component->instance()->getTable()->getActions();

    expect(InventoryAlertResource::canCreate())->toBeFalse()
        ->and(InventoryAlertResource::canDeleteAny())->toBeFalse()
        ->and(InventoryAlertResource::canForceDeleteAny())->toBeFalse()
        ->and($actions[0])->toBeInstanceOf(ViewAction::class)
        ->and($component->instance()->getTable()->getHeaderActions())->toBeEmpty()
        ->and($component->instance()->getTable()->getBulkActions())->toBeEmpty();

    $this->actingAs(User::factory()->admin()->create())
        ->get(InventoryAlertResource::getUrl('index'))
        ->assertForbidden();
});

function alertViewer(bool $withStockView = false): User
{
    $viewer = User::factory()->admin()->create();
    $permissions = [InventoryPermission::AlertView->value];

    if ($withStockView) {
        $permissions[] = InventoryPermission::StockView->value;
    }

    $viewer->givePermissionTo($permissions);

    return $viewer;
}
