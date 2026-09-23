<?php

declare(strict_types=1);

use App\Enums\InventoryConditionChangeStatus;
use App\Enums\InventoryCountStatus;
use App\Enums\InventoryReturnStatus;
use App\Enums\InvoiceStatus;
use App\Enums\OperationStage;
use App\Enums\PaymentMethodType;
use App\Enums\SupplierConfirmationStatus;
use App\Models\Bill;
use App\Models\DepositApplicationIssue;
use App\Models\InventoryConditionChange;
use App\Models\InventoryCount;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryReservation;
use App\Models\InventoryReturn;
use App\Models\InventoryReturnLine;
use App\Models\Invoice;
use App\Models\InvoiceDeliveryLink;
use App\Models\Lead;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\Supplier;
use App\Models\SupplierConfirmation;
use App\Models\SupplierPayment;
use App\Models\WarehouseReplenishmentPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('covers residual model status and display helpers', function (): void {
    $paymentMethod = new PaymentMethod;
    $paymentMethod->forceFill(['type' => PaymentMethodType::Stripe]);

    $count = new InventoryCount;
    $count->forceFill(['status' => InventoryCountStatus::Confirmed]);

    $return = new InventoryReturn;
    $return->forceFill(['status' => InventoryReturnStatus::Cancelled]);

    $operation = new InventoryOperation;
    $operation->forceFill(['stage' => OperationStage::PartiallyReceived]);

    $issue = new DepositApplicationIssue;
    $issue->forceFill(['resolved_at' => now()]);

    $lead = new Lead;
    $lead->forceFill([
        'first_name' => null,
        'last_name' => null,
        'company_name' => 'Coverage Company',
        'lead_number' => 'LEAD-COVERAGE',
    ]);

    $invoice = new Invoice;
    $invoice->forceFill(['status' => InvoiceStatus::Sent]);

    expect($paymentMethod->isStripe())->toBeTrue()
        ->and($count->isConfirmed())->toBeTrue()
        ->and($return->isCancelled())->toBeTrue()
        ->and($operation->isPartiallyReceived())->toBeTrue()
        ->and($issue->isResolved())->toBeTrue()
        ->and($lead->displayName())->toBe('Coverage Company')
        ->and($invoice->isSent())->toBeTrue();
});

it('covers persisted price provenance copying', function (): void {
    $line = QuotationLine::factory()->create();

    $source = new QuotationLine;
    $source->forceFill([
        'resolved_price_source' => 'base',
        'resolved_price_tier_id' => null,
        'price_floor_override_id' => null,
        'list_price_minor' => 2500,
        'floor_price_minor' => 2000,
    ]);

    $line->copyPriceProvenanceFrom($source);

    expect($line->refresh()->resolved_price_source?->value)->toBe('base')
        ->and($line->resolved_price_tier_id)->toBeNull()
        ->and($line->price_floor_override_id)->toBeNull()
        ->and($line->list_price_minor)->toBe(2500)
        ->and($line->floor_price_minor)->toBe(2000);
});

it('covers bill supplier-reference and linked-order residual guards', function (): void {
    $supplier = Supplier::factory()->create();

    expect(fn (): Bill => Bill::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'supplier_reference' => '   ',
    ]))->toThrow(DomainException::class);

    $orphaned = new Bill;
    $orphaned->forceFill([
        'supplier_id' => null,
        'purchase_order_id' => PHP_INT_MAX,
    ]);

    expect(fn (): int => Bill::resolveSupplierId($orphaned))
        ->toThrow(DomainException::class, 'linked purchase order could not be found');
});

it('covers invoice payment-term overdue and issued mutation branches', function (): void {
    $term = PaymentTerm::factory()->create(['due_days' => 10, 'grace_days' => 0]);
    $invoice = Invoice::factory()->create([
        'payment_term_id' => $term->getKey(),
        'issued_at' => now()->subDays(20),
        'due_date' => today()->subDay(),
        'status' => InvoiceStatus::Sent,
        'total_amount' => '100.00',
        'amount_paid' => '0.00',
    ]);
    $invoice->load('paymentTerm');

    expect($invoice->isOverdue(now()))->toBeTrue()
        ->and(fn (): bool => $invoice->update(['description' => 'forbidden mutation']))
        ->toThrow(DomainException::class, 'issued invoice cannot be changed');
});

it('covers inventory operation and reservation unloaded-relation branches', function (): void {
    $invoice = Invoice::factory()->create();
    $link = new InvoiceDeliveryLink;
    $link->setRelation('invoice', $invoice);

    $delivery = InventoryOperation::factory()->delivery()->create();
    $delivery->setRelation('invoiceDeliveryLink', $link);

    expect($invoice->relationLoaded('paymentAllocations'))->toBeFalse()
        ->and($delivery->relatedPayment())->toBeNull();

    $operation = InventoryOperation::factory()->create();
    $reservation = new InventoryReservation;
    $reservation->forceFill([
        'source_type' => 'inventory_operation',
        'source_id' => $operation->getKey(),
    ]);
    $reservation->setRelation('sourceOperation', $operation);

    expect($operation->relationLoaded('sourceDocument'))->toBeFalse()
        ->and($reservation->resolvedSourceDocument()?->is($operation))->toBeTrue();
});

it('covers quotation delivery loading and purchase-order header rejection branches', function (): void {
    $order = Order::factory()->create();

    $quotation = new Quotation;
    $quotation->forceFill(['converted_order_id' => $order->getKey()]);
    $quotation->setRelation('convertedOrder', $order);

    expect($order->relationLoaded('deliveries'))->toBeFalse()
        ->and($quotation->hasLapsedReservations())->toBeFalse();

    $purchaseOrder = PurchaseOrder::factory()->create();
    SupplierConfirmation::factory()->create([
        'purchase_order_id' => $purchaseOrder->getKey(),
        'supplier_id' => $purchaseOrder->supplier_id,
        'confirmation_status' => SupplierConfirmationStatus::Rejected,
    ]);

    expect($purchaseOrder->hasRejectedConfirmation())->toBeTrue();
});

it('covers inventory model lifecycle residual branches', function (): void {
    $lot = InventoryLot::factory()->create();
    $lot->forceFill(['origin_source_id' => 777])->save();

    $conditionChange = new InventoryConditionChange;
    $conditionChange->exists = true;
    $conditionChange->setRawAttributes(['status' => null], true);
    $conditionChange->forceFill(['status' => InventoryConditionChangeStatus::Draft]);
    $conditionChange->save();

    $allocation = PurchaseInboundAllocation::factory()->create();
    $operationLine = InventoryOperationLine::factory()->create([
        'purchase_inbound_allocation_id' => $allocation->getKey(),
    ]);

    expect(fn (): ?bool => $operationLine->delete())
        ->toThrow(DomainException::class, 'cannot be deleted');

    $return = InventoryReturn::factory()->create();
    $returnLine = InventoryReturnLine::factory()->for($return, 'inventoryReturn')->create();
    $returnLine->inventory_return_id = PHP_INT_MAX;

    expect(fn (): bool => $returnLine->save())
        ->toThrow(DomainException::class, 'must belong to an inventory return');
});

it('covers supplier-payment numeric sequencing and replenishment validation', function (): void {
    SupplierPayment::factory()->create(['supplier_payment_number' => 'SPAY-0000042']);

    $payment = SupplierPayment::query()->create([
        'supplier_id' => Supplier::factory()->create()->getKey(),
        'payment_method_id' => PaymentMethod::factory()->create()->getKey(),
        'amount' => '10.00',
        'payment_date' => today(),
        'status' => 'draft',
    ]);

    expect($payment->supplier_payment_number)->toBe('SPAY-0000043');

    expect(fn (): WarehouseReplenishmentPolicy => WarehouseReplenishmentPolicy::factory()->create([
        'min_quantity' => '-1.000000',
        'max_quantity' => '10.000000',
    ]))->toThrow(DomainException::class, 'minimum quantity cannot be negative');
});
