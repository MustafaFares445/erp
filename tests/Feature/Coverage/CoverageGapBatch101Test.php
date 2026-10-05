<?php

declare(strict_types=1);

use App\Data\Sales\OrderWorkflowProjection;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Filament\Resources\InventoryOperations\Pages\ViewInventoryOperation;
use App\Filament\Resources\Orders\Actions\OrderActions;
use App\Models\InventoryOperationLine;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Services\Sales\OrderWorkflowProjectionStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

function coverage101Projection(string $label): OrderWorkflowProjection
{
    return new OrderWorkflowProjection(
        commercialStatus: OrderStatus::Released,
        businessMilestone: 'Coverage',
        fulfillmentProgressPercent: 0.0,
        requestedBase: 0.0,
        plannedBase: 0.0,
        readyBase: 0.0,
        dispatchedBase: 0.0,
        arrivedBase: 0.0,
        returnedBase: 0.0,
        remainingBase: 0.0,
        procurementOutstandingBase: 0.0,
        invoiceTotal: 0.0,
        paidTotal: 0.0,
        creditedTotal: 0.0,
        outstandingReceivable: 0.0,
        blockerCode: null,
        blockerMessage: null,
        nextActionOwner: 'Coverage',
        nextActionLabel: $label,
        nextActionRoute: null,
        financiallySettled: false,
        completionWindowStartedAt: null,
        autoCloseDueAt: null,
        closeSource: null,
        daysUntilAutoClose: null,
    );
}

/** @param array<int, OrderWorkflowProjection> $byOrderId */
function coverage101ProjectionStore(array $byOrderId): OrderWorkflowProjectionStore
{
    $reflection = new ReflectionClass(OrderWorkflowProjectionStore::class);
    /** @var OrderWorkflowProjectionStore $store */
    $store = $reflection->newInstanceWithoutConstructor();

    $projections = [];
    foreach ($byOrderId as $orderId => $projection) {
        foreach (['default', 'sqlite', 'testing', 'mysql'] as $connection) {
            $projections[$connection.':'.serialize($orderId)] = $projection;
        }
    }

    $property = new ReflectionProperty(OrderWorkflowProjectionStore::class, 'projections');
    $property->setValue($store, $projections);

    return $store;
}

it('covers order next-step no-actor resolution and negative cache reuse', function (): void {
    auth()->logout();

    $order = Order::factory()->create();

    expect(OrderActions::nextStepTarget($order))->toBeNull()
        ->and(OrderActions::nextStepTarget($order))->toBeNull();
});

it('covers create-invoice target permission denial', function (): void {
    $user = User::factory()->create();
    $order = Order::factory()->create();

    $method = new ReflectionMethod(OrderActions::class, 'createInvoiceTarget');

    expect($method->invoke(null, $order, $user))->toBeNull();
});

it('covers projected confirm-arrival create-invoice and issue-invoice branches', function (): void {
    Gate::before(static fn (): bool => true);

    $user = User::factory()->create();
    $this->actingAs($user);

    $arrivalOrder = Order::factory()->create();
    $createInvoiceOrder = Order::factory()->create();
    $issueInvoiceOrder = Order::factory()->create();

    Invoice::factory()->create([
        'order_id' => $issueInvoiceOrder->id,
        'status' => InvoiceStatus::Draft,
        'issued_at' => null,
    ]);

    app()->instance(OrderWorkflowProjectionStore::class, coverage101ProjectionStore([
        $arrivalOrder->id => coverage101Projection('Confirm shipment arrival'),
        $createInvoiceOrder->id => coverage101Projection('Create invoice'),
        $issueInvoiceOrder->id => coverage101Projection('Issue invoice'),
    ]));

    expect(OrderActions::nextStepTarget($arrivalOrder)?->label)->toBe(__('Confirm arrival'))
        ->and(OrderActions::nextStepTarget($createInvoiceOrder))->toBeNull()
        ->and(OrderActions::nextStepTarget($issueInvoiceOrder)?->label)->toBe(__('Issue invoice'));
});

it('covers transfer receipt line validation and invalid discrepancy disposition', function (): void {
    $page = (new ReflectionClass(ViewInventoryOperation::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(ViewInventoryOperation::class, 'transferReceiptLines');

    expect(fn () => $method->invoke($page, ['lines' => ['invalid']]))
        ->toThrow(DomainException::class, 'line is invalid');

    expect(fn () => $method->invoke($page, ['lines' => [[
        'operation_line_id' => [],
        'received_transaction_quantity' => '1',
        'discrepancy_disposition' => null,
        'discrepancy_reason' => null,
    ]]]))->toThrow(DomainException::class, 'invalid field values');

    expect(fn () => $method->invoke($page, ['lines' => [[
        'operation_line_id' => '1',
        'received_transaction_quantity' => '1',
        'discrepancy_disposition' => 'not-valid',
        'discrepancy_reason' => null,
    ]]]))->toThrow(DomainException::class, 'invalid discrepancy disposition');
});

it('covers transfer receipt precision and zero conversion-factor branches', function (): void {
    $page = (new ReflectionClass(ViewInventoryOperation::class))->newInstanceWithoutConstructor();

    $quantity = new ReflectionMethod(ViewInventoryOperation::class, 'receiptTransactionQuantity');
    expect(fn () => $quantity->invoke($page, 1.1234567))
        ->toThrow(DomainException::class, 'at most six decimal places');

    $line = new InventoryOperationLine;
    $line->forceFill([
        'dispatched_base_quantity' => '5.000000',
        'received_base_quantity' => '1.000000',
        'conversion_factor_snapshot' => '0.000000',
    ]);

    $remaining = new ReflectionMethod(ViewInventoryOperation::class, 'remainingTransactionQuantity');
    expect($remaining->invoke($page, $line))->toBe('0.000000');
});
