<?php

declare(strict_types=1);

use App\Data\Inventory\InventoryDiagnosis;
use App\Enums\InventoryDiagnosticSeverity;
use App\Enums\InventoryPermission;
use App\Enums\ReservationStatus;
use App\Filament\Resources\InventoryReservations\Pages\ViewInventoryReservation;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryReservation;
use App\Models\InventoryReservationAllocation;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryViewDiagnosticsService;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

function reservationDiagManager(): User
{
    $user = User::factory()->admin()->create();
    $user->givePermissionTo(array_map(static fn (InventoryPermission $permission): string => $permission->value, InventoryPermission::cases()));

    return $user;
}

function reservationDiagnose(InventoryReservation $reservation): InventoryDiagnosis
{
    return app(InventoryViewDiagnosticsService::class)->diagnose($reservation->fresh() ?? $reservation);
}

/**
 * @param  array<string, mixed>  $operationState
 * @param  array<string, mixed>  $reservationAttributes
 * @return array{operation: InventoryOperation, line: InventoryOperationLine, reservation: InventoryReservation}
 */
function reservationDiagForOperation(string $stage, array $reservationAttributes = []): array
{
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $operation = InventoryOperation::factory()->delivery()->{$stage}()->create(['source_warehouse_id' => $warehouse->getKey()]);
    $line = InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $operation->getKey(),
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity' => 2,
        'base_quantity' => 2,
    ]);
    $reservation = InventoryReservation::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'source_type' => 'inventory_operation',
        'source_id' => $operation->getKey(),
        'source_line_type' => 'inventory_operation_line',
        'source_line_id' => $line->getKey(),
        'base_quantity' => 2,
        'status' => ReservationStatus::Active,
        ...$reservationAttributes,
    ]);
    InventoryReservationAllocation::factory()->create([
        'inventory_reservation_id' => $reservation->getKey(),
        'base_quantity' => 2,
    ]);

    return ['operation' => $operation, 'line' => $line, 'reservation' => $reservation];
}

it('explains a consistent reservation held for a ready operation', function (): void {
    $this->actingAs(reservationDiagManager());
    $reservation = reservationDiagForOperation('ready')['reservation'];

    $diagnosis = reservationDiagnose($reservation);

    expect($diagnosis->codes())->toBe(['reservation.held_for_operation'])
        ->and($diagnosis->find('reservation.held_for_operation')?->action?->allowed)->toBeTrue();
});

it('blocks an active reservation whose operation is already done', function (): void {
    $this->actingAs(reservationDiagManager());
    $reservation = reservationDiagForOperation('done')['reservation'];

    expect(reservationDiagnose($reservation)->find('reservation.source_closed')?->severity)->toBe(InventoryDiagnosticSeverity::Blocker);
});

it('blocks a reservation whose warehouse does not match its operation', function (): void {
    $this->actingAs(reservationDiagManager());
    $reservation = reservationDiagForOperation('ready', ['warehouse_id' => Warehouse::factory()->create()->getKey()])['reservation'];

    expect(reservationDiagnose($reservation)->find('reservation.line_mismatch')?->severity)->toBe(InventoryDiagnosticSeverity::Blocker);
});

it('blocks an allocation whose lot differs from the line', function (): void {
    $this->actingAs(reservationDiagManager());
    $reservation = reservationDiagForOperation('ready')['reservation'];
    $reservation->allocations()->update(['inventory_lot_id' => InventoryLot::factory()->create()->getKey()]);

    expect(reservationDiagnose($reservation)->find('reservation.allocation_mismatch')?->severity)->toBe(InventoryDiagnosticSeverity::Blocker);
});

it('warns about an overdue active reservation and withholds release without permission', function (): void {
    $reservation = InventoryReservation::factory()->create(['expires_at' => now()->subHour(), 'base_quantity' => 3]);
    InventoryReservationAllocation::factory()->create(['inventory_reservation_id' => $reservation->getKey(), 'base_quantity' => 3]);

    $this->actingAs(reservationDiagManager());
    $allowed = reservationDiagnose($reservation)->find('reservation.overdue');

    $viewer = User::factory()->admin()->create();
    $viewer->givePermissionTo(InventoryPermission::ReservationView->value);
    $this->actingAs($viewer);
    $denied = reservationDiagnose($reservation)->find('reservation.overdue');

    expect($allowed?->severity)->toBe(InventoryDiagnosticSeverity::Warning)
        ->and($allowed?->action?->allowed)->toBeTrue()
        ->and($denied?->action?->allowed)->toBeFalse();
});

it('renders the panel on the view page without changing the reservation', function (): void {
    $user = reservationDiagManager();
    $reservation = reservationDiagForOperation('done')['reservation'];

    Livewire::actingAs($user)
        ->test(ViewInventoryReservation::class, ['record' => $reservation->getKey()])
        ->assertOk()
        ->assertSee(__('inventory_view_ux.title'))
        ->assertSee(__('inventory_view_ux.ui.metric_allocations'));

    expect($reservation->fresh()?->status)->toBe(ReservationStatus::Active);
});
