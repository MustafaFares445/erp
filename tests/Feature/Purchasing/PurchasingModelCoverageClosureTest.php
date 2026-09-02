<?php

declare(strict_types=1);

use App\Models\Bill;
use App\Models\BillLine;
use App\Models\ChartAccount;
use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\SupplierProductSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('covers bill numbering, due date, aliases, relationships, and helper states', function (): void {
    $supplier = Supplier::factory()->create();
    $account = ChartAccount::factory()->create();
    $term = PaymentTerm::factory()->create(['due_days' => 15]);

    $bill = Bill::query()->create([
        'supplier_id' => $supplier->getKey(),
        'payment_term_id' => $term->getKey(),
        'expense_account_id' => $account->getKey(),
        'bill_date' => today(),
        'description' => 'Coverage bill',
        'subtotal' => '90.00',
        'tax_total' => '10.00',
        'grand_total' => '100.00',
        'paid_amount' => '25.00',
        'status' => 'draft',
    ]);

    expect($bill->bill_number)->toBe('BILL-0000001')
        ->and($bill->due_date?->toDateString())->toBe(today()->addDays(15)->toDateString())
        ->and($bill->total_amount)->toBe('100.00')
        ->and($bill->amount_paid)->toBe('25.00')
        ->and($bill->grandTotal())->toBe('100.00')
        ->and($bill->paidAmount())->toBe('25.00')
        ->and($bill->outstandingAmount())->toBe(75.0)
        ->and($bill->isDraft())->toBeTrue()
        ->and($bill->isFinanciallyImmutable())->toBeFalse()
        ->and($bill->isOpen())->toBeFalse()
        ->and($bill->supplier()->first()?->is($supplier))->toBeTrue()
        ->and($bill->purchaseOrder())->not->toBeNull()
        ->and($bill->paymentTerm()->first()?->is($term))->toBeTrue()
        ->and($bill->expenseAccount()->first()?->is($account))->toBeTrue()
        ->and($bill->lines())->not->toBeNull()
        ->and($bill->paymentAllocations())->not->toBeNull()
        ->and($bill->journalEntry())->not->toBeNull()
        ->and($bill->sourceJournalEntry())->not->toBeNull();

    $bill->update(['total_amount' => '120.00']);
    expect($bill->refresh()->grand_total)->toBe('120.00');

    $bill->update(['amount_paid' => '30.00']);
    expect($bill->refresh()->paid_amount)->toBe('30.00');
});

it('rejects duplicate active supplier references while allowing cancelled evidence to be replaced', function (): void {
    $supplier = Supplier::factory()->create();

    Bill::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'supplier_reference' => 'SUP-REF-1',
        'status' => 'draft',
    ]);

    expect(fn () => Bill::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'supplier_reference' => 'SUP-REF-1',
    ]))->toThrow(DomainException::class, 'already recorded');

    $cancelled = Bill::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'supplier_reference' => 'SUP-REF-CANCELLED',
        'status' => 'cancelled',
    ]);

    $replacement = Bill::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'supplier_reference' => 'SUP-REF-CANCELLED',
        'status' => 'draft',
    ]);

    expect($cancelled->exists)->toBeTrue()
        ->and($replacement->exists)->toBeTrue();
});

it('enforces bill financial immutability, forward-only status, and deletion protection', function (): void {
    $bill = Bill::factory()->create(['status' => 'draft']);
    $bill->forceFill(['status' => 'approved'])->save();

    expect($bill->refresh()->isFinanciallyImmutable())->toBeTrue()
        ->and($bill->isOpen())->toBeTrue();

    expect(fn () => $bill->update(['description' => 'forbidden']))
        ->toThrow(DomainException::class, 'cannot be changed');

    $bill->forceFill(['status' => 'partially_paid'])->save();
    expect($bill->refresh()->isOpen())->toBeTrue();

    expect(fn () => $bill->forceFill(['status' => 'approved'])->save())
        ->toThrow(DomainException::class, 'cannot move backwards');

    $bill->refresh()->forceFill(['status' => 'paid'])->save();
    expect($bill->refresh()->isOpen())->toBeFalse();

    expect(fn () => $bill->delete())
        ->toThrow(DomainException::class, 'cannot be deleted');
});

it('covers bill line relations, variance base cases, casts, and approved-bill guards', function (): void {
    $bill = Bill::factory()->create(['status' => 'draft']);
    $line = BillLine::factory()->create([
        'bill_id' => $bill->getKey(),
        'purchase_order_line_id' => null,
        'product_variant_id' => null,
        'quantity' => '2.500',
        'unit_price' => '12.50',
        'tax_amount' => '1.00',
        'line_total' => '32.25',
        'sort_order' => 2,
    ]);

    expect($line->bill()->first()?->is($bill))->toBeTrue()
        ->and($line->purchaseOrderLine())->not->toBeNull()
        ->and($line->productVariant())->not->toBeNull()
        ->and($line->chartAccount())->not->toBeNull()
        ->and($line->receivedQuantity())->toBe(0.0)
        ->and($line->cumulativeBilledQuantity())->toBe(0.0)
        ->and($line->hasQuantityVariance())->toBeFalse()
        ->and($line->hasUnitPriceVariance())->toBeFalse()
        ->and($line->quantity)->toBe('2.500')
        ->and($line->unit_price)->toBe('12.50')
        ->and($line->sort_order)->toBe(2);

    $bill->forceFill(['status' => 'approved'])->save();

    expect(fn () => $line->update(['description' => 'forbidden']))
        ->toThrow(DomainException::class, 'cannot be changed')
        ->and(fn () => $line->delete())
        ->toThrow(DomainException::class, 'cannot be deleted');
});

it('covers bill line purchase-order unit price variance branches', function (): void {
    $purchaseOrder = PurchaseOrder::factory()->create();
    $variant = ProductVariant::factory()->create();
    $purchaseLine = $purchaseOrder->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => '5.000000',
        'unit_cost' => '10.00',
        'line_total' => '50.00',
    ]);

    $bill = Bill::factory()->create(['purchase_order_id' => $purchaseOrder->getKey()]);
    $matching = BillLine::factory()->create([
        'bill_id' => $bill->getKey(),
        'purchase_order_line_id' => $purchaseLine->getKey(),
        'product_variant_id' => $variant->getKey(),
        'unit_price' => '10.00',
    ]);
    $different = BillLine::factory()->create([
        'bill_id' => $bill->getKey(),
        'purchase_order_line_id' => $purchaseLine->getKey(),
        'product_variant_id' => $variant->getKey(),
        'unit_price' => '11.00',
    ]);

    expect($matching->hasUnitPriceVariance())->toBeFalse()
        ->and($different->hasUnitPriceVariance())->toBeTrue()
        ->and($matching->purchaseOrderLine()->first()?->is($purchaseLine))->toBeTrue()
        ->and($matching->productVariant()->first()?->is($variant))->toBeTrue();
});

it('covers supplier payment numbering, relations, casts, and paid immutability', function (): void {
    $supplier = Supplier::factory()->create();
    $method = PaymentMethod::factory()->create();

    $payment = SupplierPayment::query()->create([
        'supplier_id' => $supplier->getKey(),
        'payment_method_id' => $method->getKey(),
        'amount' => '75.00',
        'payment_date' => today(),
        'reference' => 'PAYABLE-COVERAGE',
        'status' => 'draft',
    ]);

    expect($payment->supplier_payment_number)->toBe('SPAY-0000001')
        ->and($payment->supplier()->first()?->is($supplier))->toBeTrue()
        ->and($payment->paymentMethod()->first()?->is($method))->toBeTrue()
        ->and($payment->allocations())->not->toBeNull()
        ->and($payment->journalEntry())->not->toBeNull()
        ->and($payment->sourceJournalEntry())->not->toBeNull()
        ->and($payment->amount)->toBe('75.00')
        ->and($payment->isDraft())->toBeTrue()
        ->and($payment->isPaid())->toBeFalse();

    $payment->forceFill(['status' => 'paid'])->save();

    expect($payment->refresh()->isDraft())->toBeFalse()
        ->and($payment->isPaid())->toBeTrue()
        ->and(fn () => $payment->update(['reference' => 'changed']))
        ->toThrow(DomainException::class, 'cannot be changed')
        ->and(fn () => $payment->forceFill(['status' => 'draft'])->save())
        ->toThrow(DomainException::class, 'cannot change status')
        ->and(fn () => $payment->refresh()->delete())
        ->toThrow(DomainException::class, 'cannot be deleted');
});

it('covers supplier payment allocation evidence and append-only deletion', function (): void {
    $payment = SupplierPayment::factory()->create();
    $bill = Bill::factory()->create();

    $allocation = SupplierPaymentAllocation::factory()->create([
        'supplier_payment_id' => $payment->getKey(),
        'bill_id' => $bill->getKey(),
        'amount' => '20.00',
    ]);

    expect($allocation->supplierPayment()->first()?->is($payment))->toBeTrue()
        ->and($allocation->bill()->first()?->is($bill))->toBeTrue()
        ->and($allocation->amount)->toBe('20.00')
        ->and(fn () => $allocation->delete())
        ->toThrow(DomainException::class, 'append-only evidence');
});

it('covers supplier product support targeting, relations, casts, and invalid target combinations', function (): void {
    $supplier = Supplier::factory()->create();
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create();

    $productSupport = SupplierProductSupport::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_id' => $product->getKey(),
        'product_variant_id' => null,
        'is_active' => true,
    ]);

    $variantSupport = SupplierProductSupport::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_id' => null,
        'product_variant_id' => $variant->getKey(),
        'is_active' => false,
    ]);

    expect($productSupport->supplier()->first()?->is($supplier))->toBeTrue()
        ->and($productSupport->product()->first()?->is($product))->toBeTrue()
        ->and($productSupport->productVariant())->not->toBeNull()
        ->and($productSupport->is_active)->toBeTrue()
        ->and($variantSupport->productVariant()->first()?->is($variant))->toBeTrue()
        ->and($variantSupport->is_active)->toBeFalse();

    expect(fn () => SupplierProductSupport::query()->create([
        'supplier_id' => $supplier->getKey(),
        'product_id' => null,
        'product_variant_id' => null,
        'is_active' => true,
    ]))->toThrow(LogicException::class, 'exactly one')
        ->and(fn () => SupplierProductSupport::query()->create([
            'supplier_id' => $supplier->getKey(),
            'product_id' => $product->getKey(),
            'product_variant_id' => $variant->getKey(),
            'is_active' => true,
        ]))->toThrow(LogicException::class, 'exactly one');
});
