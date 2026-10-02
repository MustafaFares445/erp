<?php

declare(strict_types=1);

use App\Data\Inventory\InventoryBalanceSnapshot;
use App\Data\Inventory\InventoryPostingResult;
use App\Data\Inventory\TransferReceiptCommand;
use App\Data\Inventory\TransferReceiptLine;
use App\Enums\InventoryCorrectionType;
use App\Enums\InventoryPermission;
use App\Enums\MovementType;
use App\Enums\OccurrenceStatus;
use App\Enums\OperationStage;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Enums\TransferDiscrepancyDisposition;
use App\Filament\Resources\SerializedInventoryUnits\Pages\ViewSerializedInventoryUnit;
use App\Filament\Resources\SerializedInventoryUnits\Schemas\SerializedInventoryUnitInfolist;
use App\Models\InventoryCorrection;
use App\Models\InventoryCorrectionLine;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryReturnLine;
use App\Models\InventoryStock;
use App\Models\MaintenanceSchedule;
use App\Models\MaintenanceScheduleOccurrence;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Models\WarrantyEntitlement;
use App\Services\Inventory\InventoryCorrectionService;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Inventory\InventoryReturnService;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Infolists\Components\RepeatableEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

function batch63Invoke(string $class, string $method, mixed ...$arguments): mixed
{
    return new ReflectionMethod($class, $method)->invoke(app($class), ...$arguments);
}

function batch63PostingResult(
    ?string $sourceLineType,
    ?int $sourceLineId,
    int $warehouseId = 1,
): InventoryPostingResult {
    $stock = new InventoryStock;
    $stock->forceFill([
        'id' => 10,
        'product_variant_id' => 20,
        'warehouse_id' => $warehouseId,
        'on_hand_quantity' => '10.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '10.000000',
    ]);

    $movement = new InventoryMovement;
    $movement->forceFill([
        'id' => 30,
        'product_variant_id' => 20,
        'warehouse_id' => $warehouseId,
        'movement_type' => MovementType::Correction,
        'quantity' => '1.000000',
        'base_quantity_delta' => '1.000000',
        'source_type' => 'coverage_test',
        'source_id' => 1,
        'source_line_type' => $sourceLineType,
        'source_line_id' => $sourceLineId,
    ]);

    return new InventoryPostingResult(
        stock: $stock,
        movement: $movement,
        balanceBefore: new InventoryBalanceSnapshot(
            onHandQuantity: '9.000000',
            reservedQuantity: '0.000000',
            damagedQuantity: '0.000000',
            availableQuantity: '9.000000',
        ),
        serializedUnit: null,
        alreadyPosted: false,
    );
}

it('rejects a partial receipt of one serialized transfer unit before mutating stock', function (): void {
    $actor = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    $serial = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
    ]);
    $operation = InventoryOperation::factory()->internalTransfer()->inTransit()->create();

    $line = InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $operation->getKey(),
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity' => '1.000000',
        'transaction_quantity' => '1.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '1.000000',
        'dispatched_base_quantity' => '1.000000',
        'received_base_quantity' => '0.000000',
        'serialized_inventory_unit_id' => $serial->getKey(),
    ]);

    expect(fn () => app(InventoryOperationService::class)->receiveTransfer(
        $operation,
        $actor,
        new TransferReceiptCommand([
            new TransferReceiptLine(
                operationLineId: (int) $line->getKey(),
                receivedTransactionQuantity: '0.500000',
            ),
        ]),
    ))->toThrow(DomainException::class, 'must be received as one complete allocated unit');
});

it('skips already settled transfer lines when cancelling and when building a full receipt', function (): void {
    $actor = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    $operation = InventoryOperation::factory()->internalTransfer()->inTransit()->create();

    $settled = InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $operation->getKey(),
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity' => '1.000000',
        'transaction_quantity' => '1.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '1.000000',
        'dispatched_base_quantity' => '1.000000',
        'received_base_quantity' => '1.000000',
    ]);

    $outstanding = InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $operation->getKey(),
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity' => '2.000000',
        'transaction_quantity' => '2.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '2.000000',
        'dispatched_base_quantity' => '2.000000',
        'received_base_quantity' => '0.000000',
    ]);

    /** @var TransferReceiptCommand $full */
    $full = batch63Invoke(InventoryOperationService::class, 'fullTransferReceipt', $operation);
    expect($full->lines)->toHaveCount(1)
        ->and($full->lines[0]->operationLineId)->toBe($outstanding->getKey());

    $onlySettled = InventoryOperation::factory()->internalTransfer()->inTransit()->create();
    InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $onlySettled->getKey(),
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity' => '1.000000',
        'transaction_quantity' => '1.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '1.000000',
        'dispatched_base_quantity' => '1.000000',
        'received_base_quantity' => '1.000000',
    ]);

    $cancelled = app(InventoryOperationService::class)->cancel($onlySettled, $actor, 'Transfer no longer required.');
    expect($cancelled->stage)->toBe(OperationStage::Canceled);
});

it('builds serialized damaged and shortage discrepancy evidence commands', function (): void {
    $actor = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    $serial = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
    ]);
    $operation = InventoryOperation::factory()->internalTransfer()->inTransit()->create();

    $line = InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $operation->getKey(),
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity' => '1.000000',
        'transaction_quantity' => '1.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '1.000000',
        'dispatched_base_quantity' => '1.000000',
        'received_base_quantity' => '0.000000',
        'serialized_inventory_unit_id' => $serial->getKey(),
    ]);

    $method = new ReflectionMethod(InventoryOperationService::class, 'resolveTransferDiscrepancy');

    $damagedCommands = [];
    $damagedArgs = [
        $line,
        $operation,
        $variant,
        (int) $operation->source_warehouse_id,
        '1.000000',
        TransferDiscrepancyDisposition::Damaged,
        'Damaged in transit.',
        $actor,
        &$damagedCommands,
    ];
    $method->invokeArgs(app(InventoryOperationService::class), $damagedArgs);

    expect($damagedCommands)->toHaveCount(1)
        ->and($damagedCommands[0]->serializedTargetStatus)->toBe(SerializedInventoryUnitStatus::Damaged)
        ->and($damagedCommands[0]->serializedTargetStockCondition)->toBe(StockCondition::Damaged)
        ->and($damagedCommands[0]->evidenceOnly)->toBeFalse();

    $shortageCommands = [];
    $shortageArgs = [
        $line,
        $operation,
        $variant,
        (int) $operation->source_warehouse_id,
        '1.000000',
        TransferDiscrepancyDisposition::Shortage,
        'Missing in transit.',
        $actor,
        &$shortageCommands,
    ];
    $method->invokeArgs(app(InventoryOperationService::class), $shortageArgs);

    expect($shortageCommands)->toHaveCount(1)
        ->and($shortageCommands[0]->serializedTargetStatus)->toBe(SerializedInventoryUnitStatus::Unknown)
        ->and($shortageCommands[0]->serializedTargetStockCondition)->toBeNull();
});

it('validates canonical return posting provenance duplicate and missing results', function (): void {
    $service = app(InventoryReturnService::class);
    $index = new ReflectionMethod(InventoryReturnService::class, 'indexReturnPostings');
    $forLine = new ReflectionMethod(InventoryReturnService::class, 'postingForReturnLine');

    expect(fn (): mixed => $index->invoke($service, [
        batch63PostingResult('wrong_source', 7),
    ]))->toThrow(DomainException::class, 'retain its return-line provenance');

    $first = batch63PostingResult('inventory_return_line', 7);
    $second = batch63PostingResult('inventory_return_line', 7);

    expect(fn (): mixed => $index->invoke($service, [$first, $second]))
        ->toThrow(DomainException::class, 'cannot receive more than one canonical posting result');

    $line = InventoryReturnLine::factory()->create();
    expect(fn (): mixed => $forLine->invoke($service, [], $line))
        ->toThrow(DomainException::class, 'must receive exactly one canonical posting result');

    $map = $index->invoke($service, [
        batch63PostingResult('inventory_return_line', (int) $line->getKey()),
    ]);
    expect($forLine->invoke($service, $map, $line))->toBeInstanceOf(InventoryPostingResult::class);
});

it('validates canonical correction posting provenance and missing line results', function (): void {
    $service = app(InventoryCorrectionService::class);
    $index = new ReflectionMethod(InventoryCorrectionService::class, 'indexCorrectionPostings');
    $forLine = new ReflectionMethod(InventoryCorrectionService::class, 'postingsForCorrectionLine');

    expect(fn (): mixed => $index->invoke($service, [
        batch63PostingResult('wrong_source', 7),
    ]))->toThrow(DomainException::class, 'retain correction-line provenance');

    $line = InventoryCorrectionLine::factory()->create();
    expect(fn (): mixed => $forLine->invoke($service, [], $line))
        ->toThrow(DomainException::class, 'must receive one compensating movement');

    $posting = batch63PostingResult('inventory_correction_line', (int) $line->getKey());
    $map = $index->invoke($service, [$posting]);

    expect($forLine->invoke($service, $map, $line))->toHaveCount(1)
        ->and($forLine->invoke($service, $map, $line)[0])->toBe($posting);
});

it('rejects posting a correction when its original operation no longer matches the correction type', function (): void {
    $actor = User::factory()->create();
    $delivery = InventoryOperation::factory()->delivery()->done()->create();

    $correction = InventoryCorrection::factory()->create([
        'correction_type' => InventoryCorrectionType::Receipt,
        'original_inventory_operation_id' => $delivery->getKey(),
    ]);

    expect(fn () => app(InventoryCorrectionService::class)->post($correction, $actor))
        ->toThrow(DomainException::class, 'original operation must remain a completed immutable operation');
});

it('covers correction movement quantity guards', function (): void {
    $movement = new InventoryMovement;
    $movement->forceFill([
        'base_quantity_delta' => '0.000000',
        'quantity' => '0.000000',
    ]);

    expect(fn (): mixed => batch63Invoke(
        InventoryCorrectionService::class,
        'movementPositiveBaseQuantity',
        $movement,
    ))->toThrow(DomainException::class, 'positive base quantity');

    $movement->forceFill(['base_quantity_delta' => '1.000000']);
    expect(fn (): mixed => batch63Invoke(
        InventoryCorrectionService::class,
        'movementAbsoluteBaseQuantity',
        $movement,
    ))->toThrow(DomainException::class, 'negative base quantity');
});

it('covers serialized warranty helper states and preventive maintenance counts', function (): void {
    $unit = SerializedInventoryUnit::factory()->create([
        'warranty_expires_on' => null,
    ]);

    $legacy = new ReflectionMethod(SerializedInventoryUnitInfolist::class, 'legacyWarrantyState');
    $rules = new ReflectionMethod(SerializedInventoryUnitInfolist::class, 'coverageRules');
    $entitlementMethod = new ReflectionMethod(SerializedInventoryUnitInfolist::class, 'currentEntitlement');

    expect($legacy->invoke(null, $unit))->toBe('No entitlement / needs verification')
        ->and($rules->invoke(null, $unit))->toBe('No warranty coverage rules are available.')
        ->and($entitlementMethod->invoke(null, $unit))->toBeNull();

    $unit->forceFill(['warranty_expires_on' => today()->addDay()])->save();
    expect($legacy->invoke(null, $unit->refresh()))->toBe('Active (legacy)')
        ->and($rules->invoke(null, $unit->refresh()))->toContain('Legacy warranty');

    $unit->forceFill(['warranty_expires_on' => today()->subDay()])->save();
    expect($legacy->invoke(null, $unit->refresh()))->toBe('Expired (legacy)');

    $entitlement = WarrantyEntitlement::factory()->create([
        'serialized_inventory_unit_id' => $unit->getKey(),
        'covers_parts' => false,
        'covers_labour' => false,
        'covers_travel' => false,
        'covers_consumables' => false,
        'covers_third_party' => false,
    ]);
    expect($entitlementMethod->invoke(null, $unit->refresh()))->toBeInstanceOf(WarrantyEntitlement::class)
        ->and($rules->invoke(null, $unit->refresh()))->toBe('No default charge categories are covered.');

    $entitlement->forceFill([
        'covers_parts' => true,
        'covers_labour' => true,
    ])->save();
    expect($rules->invoke(null, $unit->refresh()))->toContain('Parts', 'Labour');

    (new InventoryPermissionSeeder)->run();
    $viewer = User::factory()->admin()->create();
    $viewer->givePermissionTo(InventoryPermission::StockView->value);

    $schedule = MaintenanceSchedule::factory()->create([
        'serialized_inventory_unit_id' => $unit->getKey(),
    ]);
    MaintenanceScheduleOccurrence::factory()->for($schedule, 'schedule')->create([
        'status' => OccurrenceStatus::Missed,
    ]);
    MaintenanceScheduleOccurrence::factory()->for($schedule, 'schedule')->create([
        'due_on' => now()->addMonths(2)->startOfDay(),
        'status' => OccurrenceStatus::Completed,
    ]);

    $component = Livewire::actingAs($viewer)
        ->test(ViewSerializedInventoryUnit::class, ['record' => $unit->getRouteKey()]);
    $schema = $component->instance()->getSchema('infolist');
    $entry = collect($schema?->getFlatComponents(withHidden: true) ?? [])
        ->first(static fn (mixed $candidate): bool => $candidate instanceof RepeatableEntry && $candidate->getName() === 'preventiveSchedules');

    expect($entry)->toBeInstanceOf(RepeatableEntry::class);

    $state = $entry->getState();
    expect($state)->toHaveCount(1)
        ->and($state[0]['missed_count'])->toBe(1)
        ->and($state[0]['completed_count'])->toBe(1);
});
