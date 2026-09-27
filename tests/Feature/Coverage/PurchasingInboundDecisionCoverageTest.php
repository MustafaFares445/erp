<?php

declare(strict_types=1);

use App\Data\Inventory\LogisticsInboundBlockerData;
use App\Data\Inventory\LogisticsInboundLineData;
use App\Models\PurchaseOrder;
use App\Services\Inventory\LogisticsInboundProjectionService;
use App\Services\Purchasing\Exceptions\PurchaseOrderNotCancellable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @param list<mixed> $arguments */
function purchasingInboundInvoke(string $method, array $arguments): mixed
{
    return (new ReflectionMethod(LogisticsInboundProjectionService::class, $method))
        ->invokeArgs(app(LogisticsInboundProjectionService::class), $arguments);
}

it('covers inbound blocker aggregation and decision helpers', function (): void {
    /** @var list<LogisticsInboundBlockerData> $lineBlockers */
    $lineBlockers = purchasingInboundInvoke('lineBlockers', [[
        'backordered' => '2.000000',
        'unavailable' => '1.000000',
        'over_allocated' => true,
        'awaiting_confirmation' => true,
    ]]);

    expect(array_column($lineBlockers, 'code'))->toBe([
        'awaiting_supplier_confirmation',
        'supplier_backorder',
        'supplier_unavailable',
        'over_allocated',
    ])
        ->and($lineBlockers[2]->severity)->toBe('danger')
        ->and($lineBlockers[3]->severity)->toBe('danger');

    $line = new LogisticsInboundLineData(
        purchaseOrderLineId: 1,
        purchaseInboundLineId: 1,
        sku: 'SKU-COVERAGE',
        product: 'Coverage Product',
        uom: 'EA',
        orderedBaseQuantity: '5.000000',
        confirmedBaseQuantity: '5.000000',
        backorderedBaseQuantity: '0.000000',
        allocatedBaseQuantity: '5.000000',
        receivedBaseQuantity: '0.000000',
        receiptInProgressBaseQuantity: '0.000000',
        remainingBaseQuantity: '5.000000',
        currentlyAllocatableBaseQuantity: '0.000000',
        availableToReceiveBaseQuantity: '5.000000',
        allocations: [],
        blockers: [
            new LogisticsInboundBlockerData('supplier_backorder', 'First copy'),
            new LogisticsInboundBlockerData('supplier_backorder', 'Latest copy'),
        ],
        nextAction: 'Continue receiving',
    );

    $order = PurchaseOrder::factory()->create(['expected_at' => today()->subDay()]);

    /** @var list<LogisticsInboundBlockerData> $aggregate */
    $aggregate = purchasingInboundInvoke('aggregateBlockers', [$order, [$line], '5.000000']);

    expect(array_column($aggregate, 'code'))->toBe(['supplier_backorder', 'overdue'])
        ->and($aggregate[0]->message)->toBe('Latest copy')
        ->and(purchasingInboundInvoke('isOverdue', [$order, '5.000000']))->toBeTrue()
        ->and(purchasingInboundInvoke('isOverdue', [$order, '0.000000']))->toBeFalse();

    $order->forceFill(['expected_at' => today()]);
    expect(purchasingInboundInvoke('isOverdue', [$order, '5.000000']))->toBeFalse();

    $order->forceFill(['expected_at' => null]);
    expect(purchasingInboundInvoke('isOverdue', [$order, '5.000000']))->toBeFalse();

    expect(purchasingInboundInvoke('nextAction', ['Awaiting Allocation']))->toBe('Allocate warehouse quantities')
        ->and(purchasingInboundInvoke('nextAction', ['Ready to Receive']))->toBe('Complete draft receipt')
        ->and(purchasingInboundInvoke('nextAction', ['Partially Received']))->toBe('Continue receiving')
        ->and(purchasingInboundInvoke('nextAction', ['Received']))->toBe('View details')
        ->and(purchasingInboundInvoke('decimal', ['1.25']))->toBe('1.250000')
        ->and(fn (): mixed => purchasingInboundInvoke('decimal', ['not-numeric']))
        ->toThrow(LogicException::class);
});

it('covers purchase order cancellation exception factories', function (): void {
    $order = PurchaseOrder::factory()->create([
        'purchase_order_number' => 'PO-CANCEL-COVERAGE',
    ]);

    expect(PurchaseOrderNotCancellable::hasCompletedReceipt($order)->getMessage())
        ->toContain('PO-CANCEL-COVERAGE')
        ->and(PurchaseOrderNotCancellable::hasOpenReceipt($order)->getMessage())
        ->toContain('PO-CANCEL-COVERAGE')
        ->toContain('active Inventory receipt');
});
