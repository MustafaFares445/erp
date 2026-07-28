<?php

declare(strict_types=1);

use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function operationService(): InventoryOperationService
{
    return app(InventoryOperationService::class);
}

// Pure stage-machine assertions (data-model.md §1) — no database needed.

it('allows the identical Draft/Waiting/Ready/Done/Canceled path for every operation type', function (OperationType $type): void {
    expect(OperationStage::Draft->canTransitionTo(OperationStage::Waiting, $type))->toBeTrue()
        ->and(OperationStage::Draft->canTransitionTo(OperationStage::Ready, $type))->toBeTrue()
        ->and(OperationStage::Draft->canTransitionTo(OperationStage::Canceled, $type))->toBeTrue()
        ->and(OperationStage::Waiting->canTransitionTo(OperationStage::Ready, $type))->toBeTrue()
        ->and(OperationStage::Waiting->canTransitionTo(OperationStage::Canceled, $type))->toBeTrue()
        ->and(OperationStage::Ready->canTransitionTo(OperationStage::Waiting, $type))->toBeTrue()
        ->and(OperationStage::Ready->canTransitionTo(OperationStage::Canceled, $type))->toBeTrue();
})->with([OperationType::Receipt, OperationType::Delivery, OperationType::InternalTransfer]);

it('reaches Done straight from Ready for a receipt or delivery, never through InTransit', function (OperationType $type): void {
    expect(OperationStage::Ready->canTransitionTo(OperationStage::Done, $type))->toBeTrue()
        ->and(OperationStage::Ready->canTransitionTo(OperationStage::InTransit, $type))->toBeFalse();
})->with([OperationType::Receipt, OperationType::Delivery]);

it('permits InTransit only for an internal transfer, and only between Ready and Done', function (): void {
    expect(OperationStage::Ready->canTransitionTo(OperationStage::InTransit, OperationType::InternalTransfer))->toBeTrue()
        ->and(OperationStage::Ready->canTransitionTo(OperationStage::Done, OperationType::InternalTransfer))->toBeFalse()
        ->and(OperationStage::InTransit->canTransitionTo(OperationStage::Done, OperationType::InternalTransfer))->toBeTrue()
        ->and(OperationStage::InTransit->canTransitionTo(OperationStage::Canceled, OperationType::InternalTransfer))->toBeTrue();
});

it('rejects InTransit as a target for receipt and delivery from every stage', function (OperationType $type): void {
    foreach (OperationStage::cases() as $stage) {
        expect($stage->canTransitionTo(OperationStage::InTransit, $type))->toBeFalse();
    }
})->with([OperationType::Receipt, OperationType::Delivery]);

it('treats Done and Canceled as terminal: no transition out is legal for any type', function (OperationType $type): void {
    foreach ([OperationStage::Done, OperationStage::Canceled] as $terminal) {
        foreach (OperationStage::cases() as $target) {
            expect($terminal->canTransitionTo($target, $type))->toBeFalse();
        }

        expect($terminal->isTerminal())->toBeTrue();
    }
})->with([OperationType::Receipt, OperationType::Delivery, OperationType::InternalTransfer]);

// Service-level: the same assertions enforced through InventoryOperationService.

it('refuses complete() on a receipt or delivery that is not yet Ready', function (): void {
    $operation = InventoryOperation::factory()->receipt()->draft()->create();
    $operation->lines()->create(operationLineAttributes($operation));

    expect(fn (): InventoryOperation => operationService()->complete($operation, User::factory()->create()))
        ->toThrow(DomainException::class, __('admin.inventory.operation.errors.illegal_transition', ['from' => 'draft', 'to' => 'ready']));
});

it('refuses complete() on an internal transfer that is Ready but not yet InTransit', function (): void {
    $operation = InventoryOperation::factory()->internalTransfer()->ready()->create();
    $operation->lines()->create(operationLineAttributes($operation));

    expect(fn (): InventoryOperation => operationService()->complete($operation, User::factory()->create()))
        ->toThrow(DomainException::class, __('admin.inventory.operation.errors.illegal_transition', ['from' => 'ready', 'to' => 'in_transit']));
});

it('refuses dispatch() on a receipt or delivery, since InTransit does not apply to them', function (OperationType $type): void {
    $operation = InventoryOperation::factory()->state(['operation_type' => $type])->ready()->create();
    $operation->lines()->create(operationLineAttributes($operation));

    expect(fn (): InventoryOperation => operationService()->dispatch($operation, User::factory()->create()))
        ->toThrow(DomainException::class);
})->with([OperationType::Receipt, OperationType::Delivery]);

it('refuses any transition on a Done operation and directs the caller to a correcting operation', function (): void {
    $operation = InventoryOperation::factory()->receipt()->done()->create();

    expect(fn (): InventoryOperation => operationService()->cancel($operation, User::factory()->create(), 'test'))
        ->toThrow(DomainException::class, __('admin.inventory.operation.errors.immutable'));
});

it('refuses any transition on a Canceled operation', function (): void {
    $operation = InventoryOperation::factory()->receipt()->canceled()->create();

    expect(fn (): InventoryOperation => operationService()->markReady($operation))
        ->toThrow(DomainException::class);
});

/**
 * @return array<string, mixed>
 */
function operationLineAttributes(InventoryOperation $operation): array
{
    $variant = ProductVariant::factory()->create();
    $warehouseId = $operation->source_warehouse_id ?? $operation->destination_warehouse_id;

    if (is_int($warehouseId)) {
        InventoryStock::factory()->for($variant)->for(Warehouse::find($warehouseId))->create([
            'on_hand_quantity' => '10.000',
            'available_quantity' => '10.000',
        ]);
    }

    return [
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1.000',
        'unit_id' => $variant->unit_id,
    ];
}
