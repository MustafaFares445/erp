<?php

declare(strict_types=1);

use App\Enums\OperationStage;
use App\Models\AuditLog;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function operationAuditService(): InventoryOperationService
{
    return app(InventoryOperationService::class);
}

it('writes an activity entry for marking ready, dispatching, completing and receiving a transfer', function (): void {
    $source = Warehouse::factory()->create();
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($source)->create([
        'on_hand_quantity' => '10.000', 'reserved_quantity' => '0.000', 'available_quantity' => '10.000',
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($source)->create([
        'on_hand_quantity' => '10.000', 'reserved_quantity' => '0.000', 'expires_at' => null,
    ]);
    $operation = InventoryOperation::factory()->internalTransfer()->create([
        'source_warehouse_id' => $source->getKey(),
        'destination_warehouse_id' => $destination->getKey(),
    ]);
    $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '6.000',
        'unit_id' => $variant->unit_id,
        'inventory_lot_id' => $lot->getKey(),
    ]);
    $actor = User::factory()->create();

    $service = operationAuditService();
    $ready = $service->markReady($operation->refresh(), $actor);
    $dispatched = $service->dispatch($ready, $actor);
    $received = $service->complete($dispatched, $actor);

    expect($received->stage)->toBe(OperationStage::Done);

    $readyLog = AuditLog::query()
        ->where('subject_type', InventoryOperation::class)
        ->where('subject_id', $operation->getKey())
        ->where('description', 'inventory.operation.marked_ready')
        ->sole();

    expect($readyLog->causer_id)->toBe($actor->getKey())
        ->and(data_get($readyLog->attribute_changes, 'old.stage'))->toBe(OperationStage::Draft->value)
        ->and(data_get($readyLog->attribute_changes, 'attributes.stage'))->toBe(OperationStage::Ready->value)
        ->and(data_get($readyLog->attribute_changes, 'attributes.line_count'))->toBe(1)
        ->and($readyLog->getProperty('source_channel'))->toBe('dashboard');

    $dispatchedLog = AuditLog::query()
        ->where('subject_type', InventoryOperation::class)
        ->where('subject_id', $operation->getKey())
        ->where('description', 'inventory.operation.dispatched')
        ->sole();

    expect(data_get($dispatchedLog->attribute_changes, 'old.stage'))->toBe(OperationStage::Ready->value)
        ->and(data_get($dispatchedLog->attribute_changes, 'attributes.stage'))->toBe(OperationStage::InTransit->value);

    $receivedLog = AuditLog::query()
        ->where('subject_type', InventoryOperation::class)
        ->where('subject_id', $operation->getKey())
        ->where('description', 'inventory.operation.transfer_received')
        ->sole();

    expect(data_get($receivedLog->attribute_changes, 'old.stage'))->toBe(OperationStage::InTransit->value)
        ->and(data_get($receivedLog->attribute_changes, 'attributes.stage'))->toBe(OperationStage::Done->value);
});

it('writes exactly one completed entry for a receipt and caps the line summary', function (): void {
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $operation = InventoryOperation::factory()->receipt()->create([
        'destination_warehouse_id' => $destination->getKey(),
    ]);

    foreach (range(1, 7) as $i) {
        $operation->lines()->create([
            'product_variant_id' => $variant->getKey(),
            'quantity' => '1',
            'unit_id' => $variant->unit_id,
        ]);
    }

    $actor = User::factory()->create();
    $service = operationAuditService();
    $service->markReady($operation->refresh(), $actor);
    $service->complete($operation->refresh(), $actor);

    $completedLogs = AuditLog::query()
        ->where('subject_type', InventoryOperation::class)
        ->where('subject_id', $operation->getKey())
        ->where('description', 'inventory.operation.completed')
        ->get();

    expect($completedLogs)->toHaveCount(1);

    $log = $completedLogs->sole();

    expect(data_get($log->attribute_changes, 'attributes.line_count'))->toBe(7)
        ->and(data_get($log->attribute_changes, 'attributes.lines'))->toHaveCount(5);
});

it('writes no activity entry for a completion that rolls back', function (): void {
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $operation = InventoryOperation::factory()->receipt()->create([
        'destination_warehouse_id' => $destination->getKey(),
    ]);
    $line = $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '3',
        'unit_id' => $variant->unit_id,
    ]);
    $actor = User::factory()->create();

    $service = operationAuditService();
    $ready = $service->markReady($operation->refresh(), $actor);

    // Pre-occupy the exact idempotency key `complete()` will post under, so the posting
    // service's own duplicate-key guard throws naturally, mid-transaction, before the stage
    // transition or the audit log are ever written.
    InventoryMovement::factory()->create([
        'idempotency_key' => sprintf('inventory-operation-receipt:%d:%d', $operation->getKey(), $line->getKey()),
    ]);

    expect(fn () => $service->complete($ready, $actor))
        ->toThrow(DomainException::class, 'The idempotency key is already used by a different inventory posting.');

    expect($operation->refresh()->stage)->toBe(OperationStage::Ready)
        ->and(AuditLog::query()
            ->where('subject_type', InventoryOperation::class)
            ->where('subject_id', $operation->getKey())
            ->where('description', 'inventory.operation.completed')
            ->exists())->toBeFalse();
});

it('still writes the existing cancellation entry unchanged', function (): void {
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $operation = InventoryOperation::factory()->receipt()->create([
        'destination_warehouse_id' => $destination->getKey(),
    ]);
    $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '2',
        'unit_id' => $variant->unit_id,
    ]);
    $actor = User::factory()->create();

    $service = operationAuditService();
    $service->markReady($operation->refresh(), $actor);
    $service->cancel($operation->refresh(), $actor, 'No longer needed.');

    $canceledLog = AuditLog::query()
        ->where('subject_type', InventoryOperation::class)
        ->where('subject_id', $operation->getKey())
        ->where('description', 'inventory.operation.canceled')
        ->sole();

    expect($canceledLog->causer_id)->toBe($actor->getKey());
});
