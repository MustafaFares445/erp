<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\OperationStage;
use App\Filament\Resources\Invoices\Schemas\InvoiceInfolist;
use App\Models\DepositApplicationIssue;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Services\Inventory\LogisticsInboundProjectionService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses a loaded legacy confirmation header and rejects incomplete preloading for reuse', function (): void {
    $order = new PurchaseOrder;
    $order->forceFill(['supplier_confirmation_required' => true]);
    $line = new PurchaseOrderLine;
    $line->forceFill(['base_quantity' => '5.000000']);
    $line->setRelation('purchaseInboundLine', null);
    $confirmation = new \App\Models\SupplierConfirmation;
    $confirmation->forceFill(['id' => 1, 'confirmation_status' => \App\Enums\SupplierConfirmationStatus::Confirmed]);
    $confirmation->setRelation('items', new EloquentCollection);
    $order->setRelation('confirmations', new EloquentCollection([$confirmation]));
    $service = app(\App\Services\Purchasing\PurchaseOrderSupplierCommitmentService::class);

    $quantities = $service->quantities($line, $order);
    expect($quantities['confirmed'])->toBe('5.000000')
        ->and($quantities['awaiting_confirmation'])->toBeFalse();

    $confirmation->unsetRelation('items');
    expect(new ReflectionMethod($service, 'loadedConfirmations')->invoke($service, $order))->toBeNull();
});

it('caps an over-reserved loaded purchase allocation at zero available quantity', function (): void {
    $allocation = new \App\Models\PurchaseInboundAllocation;
    $allocation->forceFill(['allocated_base_quantity' => '5.000000']);
    $operation = new InventoryOperation;
    $operation->forceFill(['operation_type' => \App\Enums\OperationType::Receipt, 'stage' => OperationStage::Done]);
    $line = new InventoryOperationLine;
    $line->forceFill(['base_quantity' => '6.000000']);
    $line->setRelation('operation', $operation);
    $allocation->setRelation('inventoryOperationLines', new EloquentCollection([$line]));
    $service = app(\App\Services\Purchasing\PurchaseOrderReceivingService::class);
    expect(new ReflectionMethod($service, 'allocationAvailableForNewReceipt')->invoke($service, $allocation))->toBe('0.000000');
});

it('covers invoice deposit-reconciliation warning and overdue banner branches', function (): void {
    $method = new ReflectionMethod(InvoiceInfolist::class, 'bannerMeta');

    $issueInvoice = Invoice::factory()->create([
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
        'due_date' => today()->addDays(10),
        'total_amount' => '100.00',
        'amount_paid' => '0.00',
        'credited_amount' => '0.00',
    ]);

    DepositApplicationIssue::factory()->create([
        'invoice_id' => $issueInvoice->id,
        'resolved_at' => null,
    ]);

    $warning = $method->invoke(null, $issueInvoice->refresh());
    expect($warning['status'])->toBe('warning')
        ->and($warning['heading'])->toBe('Reconciliation issue');

    $overdue = Invoice::factory()->create([
        'status' => InvoiceStatus::Issued,
        'issued_at' => now()->subDays(10),
        'due_date' => today()->subDay(),
        'total_amount' => '200.00',
        'amount_paid' => '0.00',
        'credited_amount' => '0.00',
    ]);

    $overdueMeta = $method->invoke(null, $overdue);
    expect($overdueMeta['status'])->toBe('danger')
        ->and($overdueMeta['heading'])->toBe('Overdue')
        ->and($overdueMeta['description'])->toContain('200');
});

it('covers logistics inbound quantity aggregation from fully preloaded receipt lines', function (): void {
    $order = PurchaseOrder::factory()->create();
    $poLine = PurchaseOrderLine::factory()->create([
        'purchase_order_id' => $order->id,
    ]);

    $doneLine = new InventoryOperationLine;
    $doneLine->forceFill([
        'purchase_order_line_id' => $poLine->id,
        'base_quantity' => '3.250000',
    ]);

    $waitingLine = new InventoryOperationLine;
    $waitingLine->forceFill([
        'purchase_order_line_id' => $poLine->id,
        'base_quantity' => '1.500000',
    ]);

    $done = new InventoryOperation;
    $done->forceFill(['stage' => OperationStage::Done]);
    $done->setRelation('lines', new EloquentCollection([$doneLine]));

    $waiting = new InventoryOperation;
    $waiting->forceFill(['stage' => OperationStage::Waiting]);
    $waiting->setRelation('lines', new EloquentCollection([$waitingLine]));

    $order->setRelation('receipts', new EloquentCollection([$done, $waiting]));

    $method = new ReflectionMethod(LogisticsInboundProjectionService::class, 'operationQuantityForLine');
    $service = app(LogisticsInboundProjectionService::class);

    expect($method->invoke($service, $poLine, [OperationStage::Done], $order))->toBe('3.250000')
        ->and($method->invoke($service, $poLine, [OperationStage::Waiting], $order))->toBe('1.500000');
});
