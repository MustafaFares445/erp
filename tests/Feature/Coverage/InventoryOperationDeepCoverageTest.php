<?php

declare(strict_types=1);

use App\Data\Inventory\TransferReceiptCommand;
use App\Data\Inventory\TransferReceiptLine;
use App\Enums\OperationStage;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Inventory\InventoryOperationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function operationCoverageInvoke(string $method, mixed ...$arguments): mixed
{
    return new ReflectionMethod(InventoryOperationService::class, $method)
        ->invoke(app(InventoryOperationService::class), ...$arguments);
}

function operationCoverageTransferLine(array $attributes = []): InventoryOperationLine
{
    $line = new InventoryOperationLine;
    $line->forceFill([
        'id' => 10,
        'product_variant_id' => 20,
        'unit_id' => 30,
        'quantity' => '2.000000',
        'transaction_quantity' => '2.000000',
        'transaction_unit_id' => 30,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '2.000000',
        'dispatched_base_quantity' => '2.000000',
        'received_base_quantity' => '0.000000',
        ...$attributes,
    ]);

    return $line;
}
it('covers transfer receipt line shape guards', function (): void {
    $line = operationCoverageTransferLine();
    $lines = new Collection([$line]);

    expect(fn (): mixed => operationCoverageInvoke(
        'receiptLinesByOperationLineId',
        new TransferReceiptCommand([]),
        $lines,
    ))->toThrow(DomainException::class, 'account for every outstanding transfer line');

    expect(fn (): mixed => operationCoverageInvoke(
        'receiptLinesByOperationLineId',
        new TransferReceiptCommand([
            new TransferReceiptLine(operationLineId: 999, receivedTransactionQuantity: '1'),
        ]),
        $lines,
    ))->toThrow(DomainException::class, 'only outstanding transfer lines');

    expect(fn (): mixed => operationCoverageInvoke(
        'receiptLinesByOperationLineId',
        new TransferReceiptCommand([
            new TransferReceiptLine(operationLineId: 10, receivedTransactionQuantity: '1'),
            new TransferReceiptLine(operationLineId: 10, receivedTransactionQuantity: '1'),
        ]),
        $lines,
    ))->toThrow(DomainException::class, 'cannot contain a line more than once');

    $line->forceFill(['received_base_quantity' => '2.000000']);
    expect(fn (): mixed => operationCoverageInvoke(
        'receiptLinesByOperationLineId',
        new TransferReceiptCommand([]),
        new Collection([$line]),
    ))->toThrow(DomainException::class, 'no outstanding lines');
});
it('covers transfer quantity snapshot guards', function (): void {
    $line = operationCoverageTransferLine(['conversion_factor_snapshot' => null]);

    expect(fn (): mixed => operationCoverageInvoke(
        'receiptBaseQuantity',
        $line,
        new TransferReceiptLine(operationLineId: 10, receivedTransactionQuantity: '1'),
    ))->toThrow(DomainException::class, 'conversion snapshot');

    $line->forceFill(['conversion_factor_snapshot' => '0']);
    expect(fn (): mixed => operationCoverageInvoke(
        'receiptBaseQuantity',
        $line,
        new TransferReceiptLine(operationLineId: 10, receivedTransactionQuantity: '1'),
    ))->toThrow(DomainException::class, 'conversion snapshot');

    expect(fn (): mixed => operationCoverageInvoke('normalizedNonNegativeQuantity', '-1'))
        ->toThrow(DomainException::class, 'non-negative decimals')
        ->and(fn (): mixed => operationCoverageInvoke('normalizedNonNegativeQuantity', '1.1234567'))
        ->toThrow(DomainException::class, 'non-negative decimals');

    $line->forceFill(['base_quantity' => null]);
    expect(fn (): mixed => operationCoverageInvoke('transferBaseQuantity', $line))
        ->toThrow(DomainException::class, 'positive normalized base quantity');

    $line->forceFill(['base_quantity' => '0']);
    expect(fn (): mixed => operationCoverageInvoke('transferBaseQuantity', $line))
        ->toThrow(DomainException::class, 'positive normalized base quantity');
});
it('covers dispatched transfer and posting snapshot guards', function (): void {
    $line = operationCoverageTransferLine(['dispatched_base_quantity' => null]);

    expect(fn (): mixed => operationCoverageInvoke('dispatchedTransferBaseQuantity', $line))
        ->toThrow(DomainException::class, 'dispatched base quantity');

    $line->forceFill(['dispatched_base_quantity' => '0']);
    expect(fn (): mixed => operationCoverageInvoke('dispatchedTransferBaseQuantity', $line))
        ->toThrow(DomainException::class, 'dispatched base quantity');

    $line = operationCoverageTransferLine(['transaction_quantity' => '0']);
    expect(fn (): mixed => operationCoverageInvoke('postingSnapshot', $line))
        ->toThrow(DomainException::class, 'complete positive UOM snapshot');

    $line = operationCoverageTransferLine(['transaction_unit_id' => null]);
    expect(fn (): mixed => operationCoverageInvoke('postingSnapshot', $line))
        ->toThrow(DomainException::class, 'complete positive UOM snapshot');
});
it('covers conversion and identifier helper guards', function (): void {
    $line = operationCoverageTransferLine(['conversion_factor_snapshot' => null]);
    expect(fn (): mixed => operationCoverageInvoke('transactionQuantityForBase', $line, '1'))
        ->toThrow(DomainException::class, 'conversion snapshot');

    $line->forceFill(['conversion_factor_snapshot' => '0']);
    expect(fn (): mixed => operationCoverageInvoke('transactionQuantityForBase', $line, '1'))
        ->toThrow(DomainException::class, 'conversion snapshot');

    expect(operationCoverageInvoke('requireWarehouse', 5))->toBe(5)
        ->and(fn (): mixed => operationCoverageInvoke('requireWarehouse', null))
        ->toThrow(DomainException::class);

    expect(fn (): mixed => operationCoverageInvoke('lineId', new InventoryOperationLine))
        ->toThrow(LogicException::class, 'integer identifiers')
        ->and(fn (): mixed => operationCoverageInvoke('operationId', new InventoryOperation))
        ->toThrow(LogicException::class, 'integer identifiers')
        ->and(operationCoverageInvoke('actorId', null))->toBeNull()
        ->and(operationCoverageInvoke('lotId', null))->toBeNull();
});
it('covers complete transfer actor and stage guards', function (): void {
    $service = app(InventoryOperationService::class);
    $transfer = InventoryOperation::factory()->internalTransfer()->create([
        'stage' => OperationStage::Ready,
    ]);

    expect(fn () => $service->complete($transfer))
        ->toThrow(DomainException::class, 'actor is required');

    $actor = User::factory()->create();
    expect(fn () => $service->complete($transfer->refresh(), $actor))
        ->toThrow(DomainException::class);
});
it('covers transfer dispatch movement missing guard', function (): void {
    $operation = InventoryOperation::factory()->internalTransfer()->create();
    $variant = ProductVariant::factory()->create();
    $line = $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1',
        'unit_id' => $variant->unit_id,
    ]);

    expect(fn (): mixed => operationCoverageInvoke('transferDispatchMovementId', $operation, $line))
        ->toThrow(DomainException::class, 'original canonical dispatch movement');
});
