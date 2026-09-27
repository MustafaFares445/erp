<?php

declare(strict_types=1);

use App\Enums\BillStatus;
use App\Enums\OperationStage;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierConfirmationStatus;
use App\Models\Bill;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\ProductVariant;
use App\Models\PurchaseInbound;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierConfirmation;
use App\Services\Purchasing\PurchaseOrderWorkflowService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @param list<mixed> $arguments */
function purchasingWorkflowInvoke(string $method, array $arguments): mixed
{
    return (new ReflectionMethod(PurchaseOrderWorkflowService::class, $method))
        ->invokeArgs(app(PurchaseOrderWorkflowService::class), $arguments);
}

it('covers the purchase order workflow decision matrix', function (): void {
    $supplier = Supplier::factory()->create(['requires_confirmation' => false]);
    $order = PurchaseOrder::factory()->for($supplier)->create();
    $order->setRelation('supplier', $supplier);

    /** @return array{0:string,1:?string,2:string,3:string} */
    $next = static function (
        PurchaseOrderStatus $status,
        string $confirmed = '1.000000',
        string $backordered = '0.000000',
        string $unavailable = '0.000000',
        string $allocated = '1.000000',
        string $inProgress = '0.000000',
        string $received = '1.000000',
        string $remaining = '0.000000',
        string $outstanding = '0.00',
        string $supplierState = 'Confirmed',
    ) use ($order): array {
        $order->forceFill(['status' => $status]);

        /** @var array{0:string,1:?string,2:string,3:string} $result */
        $result = purchasingWorkflowInvoke('next', [
            $order, $confirmed, $backordered, $unavailable, $allocated,
            $inProgress, $received, $remaining, $outstanding,
            $supplierState, 'Inbound active',
        ]);

        return $result;
    };

    expect($next(PurchaseOrderStatus::Draft)[0])->toBe('Draft');
    $order->forceFill(['rejection_reason' => 'Revise']);
    expect($next(PurchaseOrderStatus::Draft)[1])->toBe('Returned for revision');
    $order->forceFill(['rejection_reason' => null]);

    expect($next(PurchaseOrderStatus::PendingApproval)[0])->toBe('Approval required')
        ->and($next(PurchaseOrderStatus::Cancelled)[0])->toBe('Cancelled')
        ->and($next(PurchaseOrderStatus::Closed)[0])->toBe('Short closed')
        ->and($next(PurchaseOrderStatus::Received, outstanding: '1.00')[0])->toBe('Physically received')
        ->and($next(PurchaseOrderStatus::Received)[0])->toBe('Procurement complete')
        ->and($next(PurchaseOrderStatus::Accepted, supplierState: 'Awaiting supplier response')[0])->toBe('Awaiting supplier confirmation')
        ->and($next(PurchaseOrderStatus::Accepted, unavailable: '1.000000')[0])->toBe('Supplier exception')
        ->and($next(PurchaseOrderStatus::Accepted, confirmed: '0.000000', allocated: '0.000000', received: '0.000000')[0])->toBe('Supplier commitment unavailable')
        ->and($next(PurchaseOrderStatus::Accepted, allocated: '0.000000', received: '0.000000')[0])->toBe('Awaiting warehouse allocation')
        ->and($next(PurchaseOrderStatus::Accepted, inProgress: '1.000000', received: '0.000000')[0])->toBe('Receipt in progress')
        ->and($next(PurchaseOrderStatus::Accepted, remaining: '1.000000', received: '0.000000')[0])->toBe('Inbound active')
        ->and($next(PurchaseOrderStatus::Accepted, backordered: '1.000000')[0])->toBe('Backorder follow-up')
        ->and($next(PurchaseOrderStatus::Accepted, outstanding: '1.00')[0])->toBe('Accounting follow-up')
        ->and($next(PurchaseOrderStatus::Accepted)[0])->toBe('Accepted');
});

it('covers supplier financial receipt and numeric workflow helpers', function (): void {
    $supplier = Supplier::factory()->create(['requires_confirmation' => false]);
    $order = PurchaseOrder::factory()->for($supplier)->create();
    $order->setRelation('supplier', $supplier);
    $order->setRelation('confirmations', new Collection);

    expect(purchasingWorkflowInvoke('supplierState', [$order, '0.000000', '0.000000', '0.000000']))
        ->toBe('Confirmation not required');

    $order->forceFill(['sent_at' => now()]);
    expect(purchasingWorkflowInvoke('supplierState', [$order, '0.000000', '0.000000', '0.000000']))
        ->toBe('Sent · confirmation not required');

    $supplier->forceFill(['requires_confirmation' => true]);
    $order->forceFill(['supplier_confirmation_required' => true, 'sent_at' => null]);

    expect(purchasingWorkflowInvoke('supplierState', [$order, '0.000000', '0.000000', '0.000000']))
        ->toBe('Awaiting supplier response');

    $pending = new SupplierConfirmation;
    $pending->forceFill(['confirmation_status' => SupplierConfirmationStatus::Pending]);
    $order->setRelation('confirmations', new Collection([$pending]));
    expect(purchasingWorkflowInvoke('supplierState', [$order, '0.000000', '0.000000', '0.000000']))
        ->toBe('Awaiting supplier response');

    $confirmed = new SupplierConfirmation;
    $confirmed->forceFill(['confirmation_status' => SupplierConfirmationStatus::Confirmed]);
    $order->setRelation('confirmations', new Collection([$confirmed]));
    expect(purchasingWorkflowInvoke('supplierState', [$order, '1.000000', '0.000000', '0.000000']))
        ->toBe('Confirmed')
        ->and(purchasingWorkflowInvoke('supplierState', [$order, '1.000000', '1.000000', '0.000000']))
        ->toBe('Partially confirmed / backordered')
        ->and(purchasingWorkflowInvoke('supplierState', [$order, '1.000000', '0.000000', '1.000000']))
        ->toBe('Supplier rejected quantity');

    $rejected = new SupplierConfirmation;
    $rejected->forceFill(['confirmation_status' => SupplierConfirmationStatus::Rejected]);
    $order->setRelation('confirmations', new Collection([$rejected]));
    expect(purchasingWorkflowInvoke('supplierState', [$order, '0.000000', '0.000000', '0.000000']))
        ->toBe(SupplierConfirmationStatus::Rejected->label());

    $order->setRelation('bills', new Collection);
    /** @var array{0:string,1:string,2:string,3:string} $financial */
    $financial = purchasingWorkflowInvoke('financial', [$order]);
    expect($financial[3])->toBe('No accounting bill');

    $bill = new Bill;
    $bill->forceFill([
        'status' => BillStatus::Draft,
        'grand_total' => '100.00',
        'paid_amount' => '0.00',
        'total_amount' => '100.00',
        'amount_paid' => '0.00',
    ]);
    $order->setRelation('bills', new Collection([$bill]));
    $financial = purchasingWorkflowInvoke('financial', [$order]);
    expect($financial[3])->toBe('Draft bill');

    $bill->forceFill(['status' => BillStatus::Approved, 'paid_amount' => '25.00', 'amount_paid' => '25.00']);
    $financial = purchasingWorkflowInvoke('financial', [$order]);
    expect($financial[3])->toBe('Partially paid');

    $bill->forceFill(['paid_amount' => '0.00', 'amount_paid' => '0.00']);
    $financial = purchasingWorkflowInvoke('financial', [$order]);
    expect($financial[3])->toBe('Approved / unpaid');

    $bill->forceFill(['paid_amount' => '100.00', 'amount_paid' => '100.00']);
    $financial = purchasingWorkflowInvoke('financial', [$order]);
    expect($financial[3])->toBe('Paid');

    $cancelled = new Bill;
    $cancelled->forceFill([
        'status' => BillStatus::Cancelled,
        'grand_total' => '999.00',
        'paid_amount' => '0.00',
        'total_amount' => '999.00',
        'amount_paid' => '0.00',
    ]);
    $order->setRelation('bills', new Collection([$cancelled, $bill]));
    $financial = purchasingWorkflowInvoke('financial', [$order]);
    expect($financial[0])->toBe('100.00');

    $open = new InventoryOperation;
    $open->forceFill(['stage' => OperationStage::Ready]);
    $openLine = new InventoryOperationLine;
    $openLine->forceFill(['base_quantity' => '2.000000']);
    $open->setRelation('lines', new Collection([$openLine]));

    $done = new InventoryOperation;
    $done->forceFill(['stage' => OperationStage::Done]);
    $done->setRelation('lines', new Collection([$openLine]));
    $order->setRelation('receipts', new Collection([$done, $open]));

    expect(purchasingWorkflowInvoke('openReceiptQuantity', [$order]))->toBe('2.000000')
        ->and(purchasingWorkflowInvoke('nonNegativeSubtract', ['1.000000', '2.000000']))->toBe('0.000000')
        ->and(purchasingWorkflowInvoke('nonNegativeSubtract', ['2.000000', '1.000000']))->toBe('1.000000')
        ->and(purchasingWorkflowInvoke('numericString', ['1.25']))->toBe('1.25')
        ->and(fn (): mixed => purchasingWorkflowInvoke('numericString', [1]))
        ->toThrow(LogicException::class);
});

it('projects active inbound context and clamps overpaid accounting balance to zero', function (): void {
    $supplier = Supplier::factory()->create(['requires_confirmation' => false]);
    $order = PurchaseOrder::factory()->for($supplier)->accepted()->create([
        'supplier_confirmation_required' => false,
    ]);

    PurchaseInbound::factory()->for($order)->create();

    $bill = new Bill;
    $bill->forceFill([
        'status' => BillStatus::Approved,
        'grand_total' => '100.00',
        'paid_amount' => '125.00',
        'total_amount' => '100.00',
        'amount_paid' => '125.00',
    ]);
    $order->setRelation('bills', new Collection([$bill]));

    /** @var array{0:string,1:string,2:string,3:string} $financial */
    $financial = purchasingWorkflowInvoke('financial', [$order]);

    expect($financial[2])->toBe('0.00');

    $projection = app(PurchaseOrderWorkflowService::class)->project($order->refresh());

    expect($projection->logisticsState)->not->toBe('Not activated');
});

it('projects a normalized accepted purchase order', function (): void {
    $supplier = Supplier::factory()->create(['requires_confirmation' => false]);
    $order = PurchaseOrder::factory()->for($supplier)->create([
        'status' => PurchaseOrderStatus::Accepted,
        'supplier_confirmation_required' => false,
    ]);
    $variant = ProductVariant::factory()->create();

    $line = $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => '2.000000',
        'unit_cost' => '10.00',
    ]);
    $line->forceFill([
        'transaction_quantity' => '2.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '2.000000',
        'received_base_quantity' => '0.000000',
        'quantity_received' => '0.000000',
        'line_total' => '20.00',
    ])->save();

    $projection = app(PurchaseOrderWorkflowService::class)->project($order->refresh());

    expect($projection->orderedBaseQuantity)->toBe('2.000000')
        ->and($projection->confirmedBaseQuantity)->toBe('2.000000')
        ->and($projection->businessState)->toBe('Awaiting warehouse allocation');
});
