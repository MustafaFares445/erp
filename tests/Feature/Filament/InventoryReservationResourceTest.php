<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\ReservationStatus;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\InventoryReservations\InventoryReservationResource;
use App\Filament\Resources\InventoryReservations\Pages\ListInventoryReservations;
use App\Filament\Resources\InventoryReservations\Pages\ViewInventoryReservation;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

function filamentReleasableReservation(): InventoryReservation
{
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();

    InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '5.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '5.000000',
    ]);

    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '5.000000',
        'reserved_quantity' => '0.000000',
        'expires_at' => null,
    ]);

    $operation = InventoryOperation::factory()->delivery()->create([
        'source_warehouse_id' => $warehouse->getKey(),
    ]);
    $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '2',
        'unit_id' => $variant->unit_id,
        'inventory_lot_id' => $lot->getKey(),
    ]);

    app(InventoryOperationService::class)->markReady(
        $operation,
        User::factory()->create(),
    );

    return InventoryReservation::query()
        ->where('source_id', $operation->getKey())
        ->sole();
}

function reservationViewer(bool $canRelease): User
{
    $user = User::factory()->create();
    $permissions = [InventoryPermission::ReservationView->value];

    if ($canRelease) {
        $permissions[] = InventoryPermission::ReservationRelease->value;
    }

    $user->givePermissionTo($permissions);

    return $user;
}

it('uses the canonical reservation model and exposes no manual create form', function (): void {
    expect(InventoryReservationResource::getModel())->toBe(InventoryReservation::class)
        ->and(InventoryReservationResource::canCreate())->toBeFalse()
        ->and(InventoryReservationResource::form(Schema::make())->getComponents())->toBe([]);
});

it('keeps the legacy stock reservation runtime retired', function (): void {
    expect(class_exists('App\\Filament\\Resources\\StockReservations\\StockReservationResource'))->toBeFalse()
        ->and(class_exists('App\\Services\\Inventory\\ReservationService'))->toBeFalse()
        ->and(class_exists('App\\Policies\\StockReservationPolicy'))->toBeFalse();
});

it('shows the release action only to an actor with release permission on an active reservation', function (): void {
    $reservation = filamentReleasableReservation();

    Livewire::actingAs(reservationViewer(true))
        ->test(ListInventoryReservations::class)
        ->assertActionVisible(TestAction::make('release')->table($reservation));

    Livewire::actingAs(reservationViewer(false))
        ->test(ListInventoryReservations::class)
        ->assertActionHidden(TestAction::make('release')->table($reservation));
});

it('bulk releases active permitted records and skips resolved selections', function (): void {
    $active = filamentReleasableReservation();
    $resolved = InventoryReservation::factory()->create([
        'status' => ReservationStatus::Released,
        'released_at' => now()->subHour(),
        'release_reason' => 'Previously released.',
    ]);

    Livewire::actingAs(reservationViewer(true))
        ->test(ListInventoryReservations::class)
        ->selectTableRecords([$active->getKey(), $resolved->getKey()])
        ->callAction(
            TestAction::make('release_selected')->table()->bulk(),
            data: ['reason' => 'Release stale selected inventory holds.'],
        )
        ->assertNotified();

    expect($active->fresh()?->status)->toBe(ReservationStatus::Released)
        ->and($active->fresh()?->release_reason)->toBe('Release stale selected inventory holds.')
        ->and($resolved->fresh()?->status)->toBe(ReservationStatus::Released)
        ->and($resolved->fresh()?->release_reason)->toBe('Previously released.');
});

it('filters active reservations expiring within seven days', function (): void {
    $soon = InventoryReservation::factory()->create([
        'status' => ReservationStatus::Active,
        'expires_at' => now()->addDays(3),
    ]);
    $later = InventoryReservation::factory()->create([
        'status' => ReservationStatus::Active,
        'expires_at' => now()->addDays(10),
    ]);
    $expired = InventoryReservation::factory()->create([
        'status' => ReservationStatus::Expired,
        'expires_at' => now()->subDay(),
        'released_at' => now(),
    ]);

    Livewire::actingAs(reservationViewer(false))
        ->test(ListInventoryReservations::class)
        ->filterTable('expiring_within_7_days')
        ->assertCanSeeTableRecords([$soon])
        ->assertCanNotSeeTableRecords([$later, $expired]);
});

it('resolves source links to the sales order or the holding inventory operation', function (): void {
    $order = Order::factory()->create();
    $delivery = InventoryOperation::factory()->delivery()->create([
        'source_document_type' => Order::class,
        'source_document_id' => $order->getKey(),
    ]);
    $orderReservation = InventoryReservation::factory()->create([
        'source_type' => 'inventory_operation',
        'source_id' => $delivery->getKey(),
    ]);

    $standalone = InventoryOperation::factory()->delivery()->create();
    $operationReservation = InventoryReservation::factory()->create([
        'source_type' => 'inventory_operation',
        'source_id' => $standalone->getKey(),
    ]);

    expect(InventoryReservationResource::sourceDocumentLabel($orderReservation))->toBe($order->order_number)
        ->and(InventoryReservationResource::sourceDocumentUrl($orderReservation))
        ->toBe(OrderResource::getUrl('view', ['record' => $order]))
        ->and(InventoryReservationResource::sourceDocumentUrl($operationReservation))
        ->toBe(InventoryOperationResource::getUrl('view', ['record' => $standalone]));
});

it('renders the reservation detail page with allocation and lifecycle evidence', function (): void {
    $reservation = filamentReleasableReservation();

    Livewire::actingAs(reservationViewer(true))
        ->test(ViewInventoryReservation::class, ['record' => $reservation->getKey()])
        ->assertSuccessful()
        ->assertSee('Allocations')
        ->assertSee('Lifecycle evidence');
});
