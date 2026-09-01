<?php

declare(strict_types=1);

use App\Enums\AdjustmentStatus;
use App\Enums\InventoryPermission;
use App\Enums\MovementType;
use App\Enums\ReceiptStatus;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Filament\Resources\SerializedInventoryUnits\Pages\ListSerializedInventoryUnits;
use App\Filament\Resources\SerializedInventoryUnits\Pages\ViewSerializedInventoryUnit;
use App\Filament\Resources\SerializedInventoryUnits\SerializedInventoryUnitResource;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAdjustmentItem;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptItem;
use App\Models\InventorySetting;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryAdjustmentService;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Inventory\SerializedInventoryTimelineService;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
    InventorySetting::query()->create([
        'default_markup_percent' => 0,
        'expiry_alert_days' => 30,
    ]);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('tracks one device through canonical receipt transfer and adjustment movements', function (): void {
    $actor = User::factory()->admin()->create();
    $source = Warehouse::factory()->create();
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'inventory_receipt_item_id' => null,
        'serial_number' => 'SER-LIFECYCLE-1',
        'status' => SerializedInventoryUnitStatus::Pending,
        'custody_type' => SerializedCustodyType::Unknown,
    ]);
    $operations = app(InventoryOperationService::class);

    $receipt = InventoryOperation::factory()->receipt()->create([
        'destination_warehouse_id' => $source->getKey(),
    ]);
    $receipt->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity' => '1',
        'serialized_inventory_unit_id' => $unit->getKey(),
    ]);

    $operations->markReady($receipt, $actor);
    $operations->complete($receipt->refresh(), $actor);

    expect($unit->refresh()->status)->toBe(SerializedInventoryUnitStatus::Available)
        ->and($unit->warehouse_id)->toBe($source->getKey())
        ->and($unit->custody_type)->toBe(SerializedCustodyType::Warehouse);

    $transfer = InventoryOperation::factory()->internalTransfer()->create([
        'source_warehouse_id' => $source->getKey(),
        'destination_warehouse_id' => $destination->getKey(),
    ]);
    $transfer->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity' => '1',
        'serialized_inventory_unit_id' => $unit->getKey(),
    ]);

    $operations->markReady($transfer, $actor);
    $operations->dispatch($transfer->refresh(), $actor);

    expect($unit->refresh()->status)->toBe(SerializedInventoryUnitStatus::InTransit)
        ->and($unit->warehouse_id)->toBeNull()
        ->and($unit->custody_type)->toBe(SerializedCustodyType::InTransit);

    $operations->complete($transfer->refresh(), $actor);

    expect($unit->refresh()->status)->toBe(SerializedInventoryUnitStatus::Available)
        ->and($unit->warehouse_id)->toBe($destination->getKey())
        ->and($unit->custody_type)->toBe(SerializedCustodyType::Warehouse);

    $outAdjustment = InventoryAdjustment::factory()->create([
        'warehouse_id' => $destination->getKey(),
    ]);
    InventoryAdjustmentItem::factory()->create([
        'inventory_adjustment_id' => $outAdjustment->getKey(),
        'product_variant_id' => $variant->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
        'new_quantity' => 0,
    ]);
    app(InventoryAdjustmentService::class)->confirm($outAdjustment, $actor);

    expect($unit->refresh()->status)->toBe(SerializedInventoryUnitStatus::AdjustedOut)
        ->and($unit->warehouse_id)->toBeNull()
        ->and($unit->custody_reference_type)->toBe('adjustment');

    $inAdjustment = InventoryAdjustment::factory()->create([
        'warehouse_id' => $destination->getKey(),
    ]);
    InventoryAdjustmentItem::factory()->create([
        'inventory_adjustment_id' => $inAdjustment->getKey(),
        'product_variant_id' => $variant->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
        'new_quantity' => 1,
    ]);
    app(InventoryAdjustmentService::class)->confirm($inAdjustment, $actor);

    $events = app(SerializedInventoryTimelineService::class)->events($unit->refresh());

    expect($unit->refresh()->status)->toBe(SerializedInventoryUnitStatus::Available)
        ->and($unit->warehouse_id)->toBe($destination->getKey())
        ->and($unit->custody_type)->toBe(SerializedCustodyType::Warehouse)
        ->and($unit->custody_reference_type)->toBe('warehouse')
        ->and($unit->custody_reference_id)->toBe($destination->getKey())
        ->and(InventoryMovement::query()->where('serialized_inventory_unit_id', $unit->getKey())->count())->toBe(5)
        ->and(array_column($events, 'type'))->toBe([
            MovementType::Receipt->value,
            MovementType::Transfer->value,
            MovementType::Transfer->value,
            MovementType::Adjustment->value,
            MovementType::Adjustment->value,
        ])
        ->and(collect($events)->every(fn (array $event): bool => $event['synthetic'] === false))->toBeTrue()
        ->and(collect($events)->every(fn (array $event): bool => $event['transaction_quantity'] === '1.000000'))->toBeTrue()
        ->and(collect($events)->every(fn (array $event): bool => $event['transaction_unit'] !== null))->toBeTrue()
        ->and(collect($events)->every(fn (array $event): bool => in_array($event['base_quantity_delta'], ['1.000000', '-1.000000'], true)))->toBeTrue()
        ->and(app(SerializedInventoryTimelineService::class)->receiptSource($unit->refresh()))
        ->toBe('inventory_operation #'.$receipt->getKey());

    Livewire::actingAs(serializedUnitViewer())
        ->test(ViewSerializedInventoryUnit::class, ['record' => $unit->getKey()])
        ->assertSee('inventory_operation #'.$receipt->getKey())
        ->assertSee('1.000000');
});

it('adjusts one serialized unit independently from the warehouse aggregate count', function (): void {
    $actor = User::factory()->admin()->create();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $stock = InventoryStock::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'on_hand_quantity' => 2,
        'reserved_quantity' => 0,
        'available_quantity' => 2,
    ]);
    $unit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'status' => SerializedInventoryUnitStatus::Available,
        'custody_type' => SerializedCustodyType::Warehouse,
    ]);
    $adjustment = InventoryAdjustment::factory()->create(['warehouse_id' => $warehouse->getKey()]);
    InventoryAdjustmentItem::factory()->create([
        'inventory_adjustment_id' => $adjustment->getKey(),
        'product_variant_id' => $variant->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
        'new_quantity' => 0,
    ]);

    app(InventoryAdjustmentService::class)->confirm($adjustment, $actor);

    expect((float) $stock->fresh()->on_hand_quantity)->toBe(1.0)
        ->and($unit->refresh()->status)->toBe(SerializedInventoryUnitStatus::AdjustedOut)
        ->and($unit->warehouse_id)->toBeNull()
        ->and($adjustment->fresh()->status)->toBe(AdjustmentStatus::Confirmed)
        ->and(InventoryMovement::query()->where('source_type', 'adjustment')->count())->toBe(1);
});

it('does not synthesize serialized timeline history from legacy receipt tables', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $receipt = InventoryReceipt::factory()->create([
        'warehouse_id' => $warehouse->getKey(),
        'status' => ReceiptStatus::Confirmed,
        'receipt_number' => 'REC-LEGACY',
    ]);
    $item = InventoryReceiptItem::factory()->create([
        'inventory_receipt_id' => $receipt->getKey(),
        'product_variant_id' => $variant->getKey(),
        'quantity' => 1,
    ]);
    $unit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'inventory_receipt_item_id' => $item->getKey(),
        'status' => SerializedInventoryUnitStatus::Available,
    ]);

    $events = app(SerializedInventoryTimelineService::class)->events($unit);

    expect($events)->toBe([])
        ->and(app(SerializedInventoryTimelineService::class)->receiptSource($unit))->toBeNull()
        ->and(InventoryMovement::query()->count())->toBe(0);
});

it('provides a read only searchable device resource protected by stock view', function (): void {
    $viewer = serializedUnitViewer();
    $product = Product::factory()->create(['name' => 'Tracked Router']);
    $variant = ProductVariant::factory()->for($product)->create(['sku' => 'ROUTER-SKU']);
    $unit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'serial_number' => 'SER-SEARCH-123',
        'iot_number' => 'IOT-SEARCH-456',
    ]);
    $other = SerializedInventoryUnit::factory()->create();

    Livewire::actingAs($viewer)
        ->test(ListSerializedInventoryUnits::class)
        ->searchTable('IOT-SEARCH-456')
        ->assertCanSeeTableRecords([$unit])
        ->assertCanNotSeeTableRecords([$other]);

    $this->actingAs($viewer);

    foreach (['SER-SEARCH-123', 'IOT-SEARCH-456', 'ROUTER-SKU', 'Tracked Router'] as $term) {
        expect(SerializedInventoryUnitResource::getGlobalSearchResults($term))
            ->toHaveCount(1, sprintf('Device search failed for [%s]', $term));
    }

    $result = SerializedInventoryUnitResource::getGlobalSearchResults('SER-SEARCH-123')->first();

    expect($result?->url)->toBe(SerializedInventoryUnitResource::getUrl('view', ['record' => $unit]))
        ->and(SerializedInventoryUnitResource::canCreate())->toBeFalse()
        ->and(SerializedInventoryUnitResource::canDeleteAny())->toBeFalse();

    $component = Livewire::actingAs($viewer)->test(ListSerializedInventoryUnits::class);

    expect($component->instance()->getTable()->getActions())->toContainOnlyInstancesOf(ViewAction::class)
        ->and($component->instance()->getTable()->getHeaderActions())->toBeEmpty()
        ->and($component->instance()->getTable()->getBulkActions())->toBeEmpty();

    $this->actingAs(User::factory()->admin()->create())
        ->get(SerializedInventoryUnitResource::getUrl('index'))
        ->assertForbidden();
});

it('handles timeline records without receipt warehouse or source metadata', function (): void {
    $service = app(SerializedInventoryTimelineService::class);
    $unitWithoutReceipt = SerializedInventoryUnit::factory()->create([
        'inventory_receipt_item_id' => null,
    ]);

    expect($service->events($unitWithoutReceipt))->toBe([]);

    $warehouseLabel = new ReflectionMethod($service, 'warehouseLabel');
    $sourceLabel = new ReflectionMethod($service, 'sourceLabel');
    $integerKey = new ReflectionMethod($service, 'integerKey');

    expect($warehouseLabel->invoke($service, null))->toBe('No warehouse')
        ->and($sourceLabel->invoke($service, null, null))->toBe('Manual')
        ->and($sourceLabel->invoke($service, 'legacy', new stdClass))->toBe('legacy')
        ->and($integerKey->invoke($service, 'legacy'))->toBe(0);
});

it('rejects timeline rows without their required creation timestamp', function (): void {
    $service = app(SerializedInventoryTimelineService::class);
    $movementEvent = new ReflectionMethod($service, 'movementEvent');

    expect(fn (): mixed => $movementEvent->invoke($service, new InventoryMovement))
        ->toThrow(LogicException::class, 'A persisted inventory movement must have a creation timestamp.');
});

function serializedUnitViewer(): User
{
    $viewer = User::factory()->admin()->create();
    $viewer->givePermissionTo(InventoryPermission::StockView->value);

    return $viewer;
}
