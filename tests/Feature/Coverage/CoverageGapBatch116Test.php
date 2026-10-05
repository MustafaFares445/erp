<?php

declare(strict_types=1);

use App\Data\Inventory\TransferReceiptCommand;
use App\Data\Inventory\TransferReceiptLine;
use App\Enums\OperationStage;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\TransferDiscrepancyDisposition;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function coverage116MachineTransfer(): array
{
    $source = Warehouse::factory()->create();
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    InventoryStock::factory()->for($variant)->for($source)->create([
        'on_hand_quantity' => '1.000',
        'reserved_quantity' => '0.000',
        'available_quantity' => '1.000',
    ]);
    $device = SerializedInventoryUnit::factory()
        ->for($variant, 'productVariant')
        ->for($source)
        ->create(['status' => SerializedInventoryUnitStatus::Available]);

    $operation = InventoryOperation::factory()->internalTransfer()->create([
        'source_warehouse_id' => $source->id,
        'destination_warehouse_id' => $destination->id,
    ]);
    $line = $operation->lines()->create([
        'product_variant_id' => $variant->id,
        'quantity' => '1.000',
        'unit_id' => $variant->unit_id,
        'serialized_inventory_unit_id' => $device->id,
    ]);
    $actor = User::factory()->create();
    $service = app(InventoryOperationService::class);
    $service->markReady($operation, $actor);
    $service->dispatch($operation->refresh(), $actor);

    return [$operation->refresh(), $line->refresh(), $device, $actor, $service];
}

function coverage116GrainTransfer(): array
{
    $source = Warehouse::factory()->create();
    $destination = Warehouse::factory()->create();
    $actor = User::factory()->create();
    $operation = InventoryOperation::factory()->internalTransfer()->create([
        'source_warehouse_id' => $source->id,
        'destination_warehouse_id' => $destination->id,
    ]);

    $lines = [];

    foreach (['A', 'B'] as $suffix) {
        $variant = ProductVariant::factory()->grain()->create();
        InventoryStock::factory()->for($variant)->for($source)->create([
            'on_hand_quantity' => '5.000',
            'reserved_quantity' => '0.000',
            'available_quantity' => '5.000',
        ]);
        $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($source)->create([
            'lot_number' => 'COVERAGE-116-'.$suffix,
            'on_hand_quantity' => '5.000',
            'reserved_quantity' => '0.000',
        ]);
        $lines[] = $operation->lines()->create([
            'product_variant_id' => $variant->id,
            'quantity' => '2.000',
            'unit_id' => $variant->unit_id,
            'inventory_lot_id' => $lot->id,
        ]);
    }

    $service = app(InventoryOperationService::class);
    $service->markReady($operation, $actor);
    $service->dispatch($operation->refresh(), $actor);

    return [$operation->refresh(), array_map(fn (InventoryOperationLine $line) => $line->refresh(), $lines), $actor, $service];
}

it('rejects a partial receipt for a serialized transfer line', function (): void {
    [$operation, $line, , $actor, $service] = coverage116MachineTransfer();

    expect(fn () => $service->receiveTransfer(
        $operation,
        $actor,
        new TransferReceiptCommand([
            new TransferReceiptLine(
                operationLineId: $line->id,
                receivedTransactionQuantity: '0.500000',
            ),
        ]),
    ))->toThrow(DomainException::class, 'one complete allocated unit');
});

it('marks a serialized transfer unit damaged when the whole outstanding quantity is disposed as damaged', function (): void {
    [$operation, $line, $device, $actor, $service] = coverage116MachineTransfer();

    $result = $service->receiveTransfer(
        $operation,
        $actor,
        new TransferReceiptCommand([
            new TransferReceiptLine(
                operationLineId: $line->id,
                receivedTransactionQuantity: '0.000000',
                discrepancyDisposition: TransferDiscrepancyDisposition::Damaged,
                discrepancyReason: 'Unit arrived physically damaged.',
            ),
        ]),
    );

    expect($result->stage)->toBe(OperationStage::Done)
        ->and($device->refresh()->status)->toBe(SerializedInventoryUnitStatus::Damaged);
});

it('skips already settled transfer lines during full receipt and receive processing', function (): void {
    [$operation, $lines, $actor, $service] = coverage116GrainTransfer();
    [$settled, $outstanding] = $lines;

    $settled->forceFill([
        'discrepancy_disposition' => TransferDiscrepancyDisposition::Cancelled,
        'discrepancy_reason' => 'Already settled before the next receipt.',
    ])->save();

    $fullReceipt = new ReflectionMethod(InventoryOperationService::class, 'fullTransferReceipt');
    $command = $fullReceipt->invoke($service, $operation->refresh());

    expect($command)->toBeInstanceOf(TransferReceiptCommand::class)
        ->and($command->lines)->toHaveCount(1)
        ->and($command->lines[0]->operationLineId)->toBe($outstanding->id);

    $received = $service->receiveTransfer($operation->refresh(), $actor, $command);

    expect($received->stage)->toBe(OperationStage::Done);
});

it('skips already settled transfer lines while cancelling an in-transit transfer', function (): void {
    [$operation, $lines, $actor, $service] = coverage116GrainTransfer();
    [$settled] = $lines;

    $settled->forceFill([
        'discrepancy_disposition' => TransferDiscrepancyDisposition::Cancelled,
        'discrepancy_reason' => 'Already settled before cancellation.',
    ])->save();

    $cancelled = $service->cancel($operation->refresh(), $actor, 'Cancel remaining transfer quantities.');

    expect($cancelled->stage)->toBe(OperationStage::Canceled);
});
