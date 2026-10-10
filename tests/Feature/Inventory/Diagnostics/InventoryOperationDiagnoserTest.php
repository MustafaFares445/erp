<?php

declare(strict_types=1);

use App\Data\Inventory\InventoryDiagnosis;
use App\Enums\InventoryDiagnosticSeverity;
use App\Enums\InventoryPermission;
use App\Enums\ReservationStatus;
use App\Enums\ShipmentStatus;
use App\Filament\Resources\InventoryOperations\Pages\ViewInventoryOperation;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\Shipment;
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

function opDiagManager(): User
{
    $user = User::factory()->admin()->create();
    $user->givePermissionTo(array_map(static fn (InventoryPermission $permission): string => $permission->value, InventoryPermission::cases()));

    return $user;
}

function opDiagnose(InventoryOperation $operation): InventoryDiagnosis
{
    return app(InventoryViewDiagnosticsService::class)->diagnose($operation->fresh() ?? $operation);
}

function opDiagStock(ProductVariant $variant, Warehouse $warehouse, float $onHand, float $reserved = 0.0): InventoryStock
{
    return InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => $onHand,
        'reserved_quantity' => $reserved,
        'damaged_quantity' => 0,
        'available_quantity' => $onHand - $reserved,
    ]);
}

function opDiagLine(InventoryOperation $operation, ProductVariant $variant, float $quantity, array $attributes = []): InventoryOperationLine
{
    return InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $operation->getKey(),
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity' => $quantity,
        'base_quantity' => $quantity,
        ...$attributes,
    ]);
}

it('explains a waiting delivery with the per-SKU shortage at the source warehouse', function (): void {
    $this->actingAs(opDiagManager());
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    opDiagStock($variant, $warehouse, 3, 1);
    $operation = InventoryOperation::factory()->delivery()->waiting()->create(['source_warehouse_id' => $warehouse->getKey()]);
    opDiagLine($operation, $variant, 5);

    $diagnosis = opDiagnose($operation);
    $finding = $diagnosis->find('operation.waiting_shortage');

    expect($finding?->severity)->toBe(InventoryDiagnosticSeverity::Blocker)
        ->and($finding->facts ?? [])->toHaveCount(2)
        ->and($diagnosis->has('operation.stock_now_available'))->toBeFalse();
});

it('warns that a draft delivery will be held at Waiting when stock is short', function (): void {
    $this->actingAs(opDiagManager());
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $operation = InventoryOperation::factory()->delivery()->draft()->create(['source_warehouse_id' => $warehouse->getKey()]);
    opDiagLine($operation, $variant, 2);

    expect(opDiagnose($operation)->find('operation.draft_shortage')?->severity)->toBe(InventoryDiagnosticSeverity::Warning);
});

it('says stock is now available for a waiting operation and respects the Mark Ready permission', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    opDiagStock($variant, $warehouse, 10);
    $operation = InventoryOperation::factory()->delivery()->waiting()->create(['source_warehouse_id' => $warehouse->getKey()]);
    opDiagLine($operation, $variant, 4);

    $this->actingAs(opDiagManager());
    $allowed = opDiagnose($operation)->find('operation.stock_now_available');

    $viewer = User::factory()->admin()->create();
    $viewer->givePermissionTo(InventoryPermission::DeliveryView->value);
    $this->actingAs($viewer);
    $denied = opDiagnose($operation)->find('operation.stock_now_available');

    expect($allowed?->severity)->toBe(InventoryDiagnosticSeverity::Notice)
        ->and($allowed?->action?->allowed)->toBeTrue()
        ->and($denied?->action?->allowed)->toBeFalse();
});

it('flags tracking details Mark Ready would reject', function (): void {
    $this->actingAs(opDiagManager());
    $warehouse = Warehouse::factory()->create();
    $machine = ProductVariant::factory()->machine()->create();
    $lotTracked = ProductVariant::factory()->expiryMaterial()->create();
    opDiagStock($machine, $warehouse, 5);
    opDiagStock($lotTracked, $warehouse, 5);
    $delivery = InventoryOperation::factory()->delivery()->draft()->create(['source_warehouse_id' => $warehouse->getKey()]);
    opDiagLine($delivery, $machine, 1);
    opDiagLine($delivery, $lotTracked, 1);

    $receipt = InventoryOperation::factory()->receipt()->draft()->create();
    opDiagLine($receipt, ProductVariant::factory()->expiryMaterial()->create(), 3);

    $outbound = opDiagnose($delivery);

    expect($outbound->find('operation.serial_missing')?->severity)->toBe(InventoryDiagnosticSeverity::Warning)
        ->and($outbound->has('operation.lot_missing'))->toBeTrue()
        ->and(opDiagnose($receipt)->has('operation.expiry_missing'))->toBeTrue();
});

it('blocks a ready delivery without reservations or a planned shipment', function (): void {
    $this->actingAs(opDiagManager());
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $operation = InventoryOperation::factory()->delivery()->ready()->create(['source_warehouse_id' => $warehouse->getKey()]);
    opDiagLine($operation, $variant, 2);

    $diagnosis = opDiagnose($operation);

    expect($diagnosis->find('operation.reservation_missing')?->severity)->toBe(InventoryDiagnosticSeverity::Blocker)
        ->and($diagnosis->find('operation.no_planned_shipment')?->severity)->toBe(InventoryDiagnosticSeverity::Blocker)
        ->and($diagnosis->has('operation.delivery_ready'))->toBeFalse();
});

it('guides a fully reserved ready delivery with a planned shipment to dispatch', function (): void {
    $this->actingAs(opDiagManager());
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $operation = InventoryOperation::factory()->delivery()->ready()->create(['source_warehouse_id' => $warehouse->getKey()]);
    $line = opDiagLine($operation, $variant, 2);
    InventoryReservation::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'source_type' => 'inventory_operation',
        'source_id' => $operation->getKey(),
        'source_line_type' => 'inventory_operation_line',
        'source_line_id' => $line->getKey(),
        'base_quantity' => 2,
        'status' => ReservationStatus::Active,
    ]);
    Shipment::factory()->create([
        'inventory_operation_id' => $operation->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'status' => ShipmentStatus::Planned,
    ]);

    $diagnosis = opDiagnose($operation);

    expect($diagnosis->codes())->toBe(['operation.delivery_ready'])
        ->and($diagnosis->severity())->toBe(InventoryDiagnosticSeverity::Notice);
});

it('reports open and partially received transfer quantities', function (): void {
    $this->actingAs(opDiagManager());
    $variant = ProductVariant::factory()->create();
    $inTransit = InventoryOperation::factory()->internalTransfer()->inTransit()->create(['dispatched_at' => now()->subDays(3)]);
    opDiagLine($inTransit, $variant, 10)->forceFill(['dispatched_base_quantity' => 10, 'received_base_quantity' => 0])->save();

    $partial = InventoryOperation::factory()->internalTransfer()->inTransit()->create(['dispatched_at' => now()->subDays(5)]);
    $partial->forceFill(['stage' => 'partially_received'])->save();
    opDiagLine($partial, $variant, 10)->forceFill(['dispatched_base_quantity' => 10, 'received_base_quantity' => 4])->save();

    expect(opDiagnose($inTransit)->find('operation.transfer_in_transit')?->severity)->toBe(InventoryDiagnosticSeverity::Notice)
        ->and(opDiagnose($partial)->find('operation.transfer_partially_received')?->severity)->toBe(InventoryDiagnosticSeverity::Warning);
});

it('flags a completed operation that still holds an active reservation or posted nothing', function (): void {
    $this->actingAs(opDiagManager());
    $variant = ProductVariant::factory()->create();
    $operation = InventoryOperation::factory()->delivery()->done()->create();
    $line = opDiagLine($operation, $variant, 1);
    InventoryReservation::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'source_type' => 'inventory_operation',
        'source_id' => $operation->getKey(),
        'source_line_id' => $line->getKey(),
        'status' => ReservationStatus::Active,
    ]);

    $diagnosis = opDiagnose($operation);

    expect($diagnosis->find('operation.stray_reservations')?->severity)->toBe(InventoryDiagnosticSeverity::Blocker)
        ->and($diagnosis->find('operation.done_without_movements')?->severity)->toBe(InventoryDiagnosticSeverity::Warning);
});

it('gives only guidance for a canceled operation', function (): void {
    $this->actingAs(opDiagManager());
    $operation = InventoryOperation::factory()->delivery()->canceled()->create();

    expect(opDiagnose($operation)->codes())->toBe(['operation.canceled']);
});

it('renders the panel and key figures on the view page without changing the operation', function (): void {
    $user = opDiagManager();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $operation = InventoryOperation::factory()->delivery()->waiting()->create(['source_warehouse_id' => $warehouse->getKey()]);
    opDiagLine($operation, $variant, 5);
    $before = $operation->fresh()?->updated_at?->toDateTimeString();

    Livewire::actingAs($user)
        ->test(ViewInventoryOperation::class, ['record' => $operation->getKey()])
        ->assertOk()
        ->assertSee(__('inventory_view_ux.title'))
        ->assertSee(__('inventory_view_ux.ui.metric_short_items'))
        ->assertSee($variant->sku);

    $after = $operation->fresh();

    expect($after?->stage->value)->toBe('waiting')
        ->and($after?->updated_at?->toDateTimeString())->toBe($before)
        ->and(InventoryReservation::query()->count())->toBe(0);
});
