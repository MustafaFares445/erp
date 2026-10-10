<?php

declare(strict_types=1);

use App\Data\Inventory\InventoryDiagnosis;
use App\Enums\InventoryDiagnosticSeverity;
use App\Enums\InventoryPermission;
use App\Enums\ShipmentStatus;
use App\Filament\Resources\Shipments\Pages\ViewShipment;
use App\Models\InventoryOperation;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Inventory\InventoryViewDiagnosticsService;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

function shipmentDiagManager(): User
{
    $user = User::factory()->admin()->create();
    $user->givePermissionTo(array_map(static fn (InventoryPermission $permission): string => $permission->value, InventoryPermission::cases()));

    return $user;
}

function shipmentDiagnose(Shipment $shipment): InventoryDiagnosis
{
    return app(InventoryViewDiagnosticsService::class)->diagnose($shipment->fresh() ?? $shipment);
}

function shipmentDiagFor(string $deliveryStage, ShipmentStatus $status): Shipment
{
    $delivery = InventoryOperation::factory()->delivery()->{$deliveryStage}()->create();

    return Shipment::factory()->create([
        'inventory_operation_id' => $delivery->getKey(),
        'warehouse_id' => $delivery->source_warehouse_id,
        'status' => $status,
    ]);
}

it('guides arrival confirmation for an in-transit shipment and respects the confirm permission', function (): void {
    $shipment = shipmentDiagFor('done', ShipmentStatus::InTransit);

    $this->actingAs(shipmentDiagManager());
    $allowed = shipmentDiagnose($shipment);

    $viewer = User::factory()->admin()->create();
    $viewer->givePermissionTo(InventoryPermission::ShipmentView->value);
    $this->actingAs($viewer);
    $denied = shipmentDiagnose($shipment)->find('shipment.in_transit');

    expect($allowed->codes())->toBe(['shipment.in_transit'])
        ->and($allowed->find('shipment.in_transit')?->action?->allowed)->toBeTrue()
        ->and($denied?->action?->allowed)->toBeFalse();
});

it('blocks an in-transit shipment whose delivery has not left the warehouse', function (): void {
    $this->actingAs(shipmentDiagManager());

    expect(shipmentDiagnose(shipmentDiagFor('ready', ShipmentStatus::InTransit))->find('shipment.in_transit_delivery_not_done')?->severity)
        ->toBe(InventoryDiagnosticSeverity::Blocker);
});

it('explains a planned shipment by its delivery stage', function (): void {
    $this->actingAs(shipmentDiagManager());

    expect(shipmentDiagnose(shipmentDiagFor('ready', ShipmentStatus::Planned))->codes())->toBe(['shipment.planned'])
        ->and(shipmentDiagnose(shipmentDiagFor('done', ShipmentStatus::Planned))->find('shipment.planned_delivery_done')?->severity)
        ->toBe(InventoryDiagnosticSeverity::Warning);
});

it('renders the panel on the registered shipment View page without changing the shipment', function (): void {
    $user = shipmentDiagManager();
    $shipment = shipmentDiagFor('done', ShipmentStatus::InTransit);

    Livewire::actingAs($user)
        ->test(ViewShipment::class, ['record' => $shipment->getKey()])
        ->assertOk()
        ->assertSee(__('inventory_view_ux.title'))
        ->assertSee(__('inventory_view_ux.ui.metric_days_in_transit'));

    expect($shipment->fresh()?->status)->toBe(ShipmentStatus::InTransit);
});
