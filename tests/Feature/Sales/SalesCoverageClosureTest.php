<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Models\CreditNote;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\InvoiceConfirmation;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\TaxRecognitionEntry;
use App\Models\User;
use App\Policies\CreditNotePolicy;
use App\Policies\InvoiceConfirmationPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\PaymentMethodPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\PaymentTermPolicy;
use App\Policies\QuotationPolicy;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new AccountingPermissionSeeder)->run();
    (new SalesPermissionSeeder)->run();
});

it('covers invoice line relations, casts, and invoice helpers', function (): void {
    $invoice = Invoice::factory()->create([
        'total_amount' => '120.00',
        'amount_paid' => '20.00',
        'credited_amount' => '10.00',
    ]);
    $variant = ProductVariant::factory()->create();
    $order = Order::factory()->create();
    $orderLine = OrderLine::factory()->create([
        'order_id' => $order->getKey(),
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
    ]);

    $line = $invoice->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'order_line_id' => $orderLine->getKey(),
        'description' => 'Coverage line',
        'quantity' => '2.500',
        'unit_price' => '10.00',
        'tax_amount' => '1.00',
        'line_total' => '26.00',
        'sort_order' => 1,
    ]);

    expect($line->quantity)->toBe('2.500')
        ->and($line->invoice()->first()?->is($invoice))->toBeTrue()
        ->and($line->productVariant()->first()?->is($variant))->toBeTrue()
        ->and($line->orderLine()->first()?->is($orderLine))->toBeTrue()
        ->and($line->creditNoteLines())->not->toBeNull()
        ->and($invoice->customer())->not->toBeNull()
        ->and($invoice->inventoryOperation())->not->toBeNull()
        ->and($invoice->order())->not->toBeNull()
        ->and($invoice->paymentTerm())->not->toBeNull()
        ->and($invoice->lines())->not->toBeNull()
        ->and($invoice->confirmations())->not->toBeNull()
        ->and($invoice->paymentAllocations())->not->toBeNull()
        ->and($invoice->creditNotes())->not->toBeNull()
        ->and($invoice->outstandingAmount())->toBe(90.0)
        ->and($invoice->isDraft())->toBeFalse()
        ->and($invoice->isIssued())->toBeFalse();

    $invoice->registerMediaCollections();
    $invoice->forceFill(['issued_at' => now()])->save();

    expect($invoice->isIssued())->toBeTrue()
        ->and(fn () => $invoice->delete())
        ->toThrow(DomainException::class, 'An issued invoice cannot be deleted.');
});

it('covers credit note relationships, line casts, confirmation state, media, and deletion guard', function (): void {
    $invoice = Invoice::factory()->create();
    $customer = $invoice->customer;
    $invoiceLine = $invoice->lines()->create([
        'description' => 'Original',
        'quantity' => '3.000',
        'unit_price' => '10.00',
        'tax_amount' => '1.50',
        'line_total' => '31.50',
        'sort_order' => 1,
    ]);

    $credit = CreditNote::query()->create([
        'credit_note_number' => 'CN-COVERAGE-1',
        'invoice_id' => $invoice->getKey(),
        'customer_id' => $customer->getKey(),
        'reason' => 'Coverage',
        'issue_date' => today(),
        'subtotal' => '10.00',
        'tax_total' => '0.50',
        'grand_total' => '10.50',
        'status' => 'draft',
    ]);

    $line = $credit->lines()->create([
        'invoice_line_id' => $invoiceLine->getKey(),
        'description' => 'Credit line',
        'quantity' => '1.250',
        'unit_price' => '10.00',
        'tax_amount' => '0.50',
        'line_total' => '13.00',
        'sort_order' => 1,
    ]);

    expect($credit->invoice()->first()?->is($invoice))->toBeTrue()
        ->and($credit->customer()->first()?->is($customer))->toBeTrue()
        ->and($credit->lines()->first()?->is($line))->toBeTrue()
        ->and($credit->isConfirmed())->toBeFalse()
        ->and($line->creditNote()->first()?->is($credit))->toBeTrue()
        ->and($line->invoiceLine()->first()?->is($invoiceLine))->toBeTrue()
        ->and($line->quantity)->toBe('1.250');

    $credit->registerMediaCollections();
    $credit->forceFill(['confirmed_at' => now()])->save();

    expect($credit->isConfirmed())->toBeTrue()
        ->and(fn () => $credit->delete())
        ->toThrow(DomainException::class, 'A confirmed credit note cannot be deleted.');
});

it('covers payment relations, allocation, manual evidence, tax evidence, media, and posted immutability', function (): void {
    $customer = CustomerProfile::factory()->create();
    $method = PaymentMethod::factory()->create();
    $invoice = Invoice::factory()->create(['customer_id' => $customer->getKey()]);

    $payment = Payment::query()->create([
        'payment_number' => 'PAY-COVERAGE-1',
        'customer_id' => $customer->getKey(),
        'payment_method_id' => $method->getKey(),
        'amount' => '40.00',
        'currency' => 'USD',
        'source' => 'manual',
        'payment_date' => today(),
        'status' => 'draft',
    ]);

    $allocation = $payment->allocations()->create([
        'invoice_id' => $invoice->getKey(),
        'amount' => '40.00',
    ]);

    $manual = $payment->manualRecord()->create([
        'reference' => 'BANK-COVERAGE',
        'received_at' => now(),
    ]);

    $tax = TaxRecognitionEntry::factory()->create([
        'source_type' => Invoice::class,
        'source_id' => $invoice->getKey(),
        'tax_amount' => '2.50',
    ]);

    expect($payment->customer()->first()?->is($customer))->toBeTrue()
        ->and($payment->paymentMethod()->first()?->is($method))->toBeTrue()
        ->and($payment->reversedBy())->not->toBeNull()
        ->and($payment->allocations()->first()?->is($allocation))->toBeTrue()
        ->and($payment->manualRecord()->first()?->is($manual))->toBeTrue()
        ->and($payment->amount)->toBe('40.00')
        ->and($payment->isPosted())->toBeFalse()
        ->and($allocation->payment()->first()?->is($payment))->toBeTrue()
        ->and($allocation->invoice()->first()?->is($invoice))->toBeTrue()
        ->and($allocation->amount)->toBe('40.00')
        ->and($manual->payment()->first()?->is($payment))->toBeTrue()
        ->and($manual->received_at)->not->toBeNull()
        ->and($tax->source()->first()?->is($invoice))->toBeTrue()
        ->and($tax->tax_amount)->toBe('2.50');

    $payment->registerMediaCollections();
    DB::table('payments')->where('id', $payment->getKey())->update(['posted_at' => now()]);
    $payment->refresh();

    expect($payment->isPosted())->toBeTrue()
        ->and(fn () => $payment->update(['notes' => 'forbidden']))
        ->toThrow(DomainException::class, 'A posted payment cannot be edited.')
        ->and(fn () => $payment->delete())
        ->toThrow(DomainException::class, 'A posted payment cannot be deleted.');
});

it('covers invoice confirmation relations, cast, media collection, and append-only guards', function (): void {
    $invoice = Invoice::factory()->create();
    $user = User::factory()->create();

    $confirmation = $invoice->confirmations()->create([
        'confirmed_by_user_id' => $user->getKey(),
        'confirmation_type' => 'customer',
        'confirmed_at' => now(),
        'notes' => 'Confirmed',
    ]);

    expect($confirmation->invoice()->first()?->is($invoice))->toBeTrue()
        ->and($confirmation->confirmedBy()->first()?->is($user))->toBeTrue()
        ->and($confirmation->confirmed_at)->not->toBeNull();

    $confirmation->registerMediaCollections();

    expect(fn () => $confirmation->update(['notes' => 'changed']))
        ->toThrow(DomainException::class, 'Invoice confirmations are append-only.')
        ->and(fn () => $confirmation->delete())
        ->toThrow(DomainException::class, 'Invoice confirmations are append-only.');
});

it('covers sales policies for admin bypass, scoped grants, immutable records, and denials', function (): void {
    $admin = User::factory()->admin()->create();
    $billing = User::factory()->admin()->create();
    $billing->assignRole(DashboardRole::BillingOfficer->value);
    $nobody = User::factory()->employee()->create();

    $draftInvoice = Invoice::factory()->create(['status' => 'draft', 'issued_at' => null]);
    $issuedInvoice = Invoice::factory()->create(['status' => 'issued', 'issued_at' => now()]);

    $invoicePolicy = new InvoicePolicy;
    expect($invoicePolicy->forceDelete())->toBeFalse()
        ->and($invoicePolicy->viewAny($admin))->toBeTrue()
        ->and($invoicePolicy->view($admin))->toBeTrue()
        ->and($invoicePolicy->viewAny($billing))->toBeTrue()
        ->and($invoicePolicy->view($billing))->toBeTrue()
        ->and($invoicePolicy->create($billing))->toBeTrue()
        ->and($invoicePolicy->update($billing, $draftInvoice))->toBeTrue()
        ->and($invoicePolicy->delete($billing, $draftInvoice))->toBeTrue()
        ->and($invoicePolicy->update($billing, $issuedInvoice))->toBeFalse()
        ->and($invoicePolicy->delete($billing, $issuedInvoice))->toBeFalse()
        ->and($invoicePolicy->viewAny($nobody))->toBeFalse();

    $paymentPolicy = new PaymentPolicy;
    $draftPayment = new Payment;
    $postedPayment = new Payment;
    $postedPayment->posted_at = now();

    expect($paymentPolicy->forceDelete())->toBeFalse()
        ->and($paymentPolicy->viewAny($admin))->toBeTrue()
        ->and($paymentPolicy->view($admin))->toBeTrue()
        ->and($paymentPolicy->create($admin))->toBeTrue()
        ->and($paymentPolicy->update($admin, $draftPayment))->toBeTrue()
        ->and($paymentPolicy->delete($admin, $draftPayment))->toBeTrue()
        ->and($paymentPolicy->update($admin, $postedPayment))->toBeFalse()
        ->and($paymentPolicy->delete($admin, $postedPayment))->toBeFalse();

    $creditPolicy = new CreditNotePolicy;
    $draftCredit = new CreditNote;
    $confirmedCredit = new CreditNote;
    $confirmedCredit->confirmed_at = now();

    expect($creditPolicy->forceDelete())->toBeFalse()
        ->and($creditPolicy->viewAny($admin))->toBeTrue()
        ->and($creditPolicy->view($admin))->toBeTrue()
        ->and($creditPolicy->create($admin))->toBeTrue()
        ->and($creditPolicy->update($admin, $draftCredit))->toBeTrue()
        ->and($creditPolicy->delete($admin, $draftCredit))->toBeTrue()
        ->and($creditPolicy->update($admin, $confirmedCredit))->toBeFalse()
        ->and($creditPolicy->delete($admin, $confirmedCredit))->toBeFalse();

    $methodPolicy = new PaymentMethodPolicy;
    expect($methodPolicy->forceDelete())->toBeFalse()
        ->and($methodPolicy->viewAny($admin))->toBeTrue()
        ->and($methodPolicy->view($admin))->toBeTrue()
        ->and($methodPolicy->create($admin))->toBeTrue()
        ->and($methodPolicy->update($admin))->toBeTrue();

    $termPolicy = new PaymentTermPolicy;
    expect($termPolicy->forceDelete())->toBeFalse()
        ->and($termPolicy->viewAny($admin))->toBeTrue()
        ->and($termPolicy->view($admin))->toBeTrue()
        ->and($termPolicy->create($admin))->toBeTrue()
        ->and($termPolicy->update($admin))->toBeTrue()
        ->and($termPolicy->delete($admin))->toBeTrue();

    $quotationPolicy = new QuotationPolicy;
    $draftQuotation = new Quotation;
    $draftQuotation->status = 'draft';
    $frozenQuotation = new Quotation;
    $frozenQuotation->status = 'sent';

    expect($quotationPolicy->forceDelete())->toBeFalse()
        ->and($quotationPolicy->viewAny($admin))->toBeTrue()
        ->and($quotationPolicy->view($admin))->toBeTrue()
        ->and($quotationPolicy->create($admin))->toBeTrue()
        ->and($quotationPolicy->update($admin, $draftQuotation))->toBeTrue()
        ->and($quotationPolicy->delete($admin, $draftQuotation))->toBeTrue()
        ->and($quotationPolicy->update($admin, $frozenQuotation))->toBeFalse()
        ->and($quotationPolicy->delete($admin, $frozenQuotation))->toBeFalse()
        ->and($quotationPolicy->send($admin))->toBeTrue()
        ->and($quotationPolicy->decide($admin))->toBeTrue()
        ->and($quotationPolicy->convert($admin))->toBeTrue();
});

it('covers the deny-all invoice confirmation policy', function (): void {
    $policy = new InvoiceConfirmationPolicy;
    $user = User::factory()->create();
    $confirmation = new InvoiceConfirmation;

    expect($policy->viewAny($user))->toBeFalse()
        ->and($policy->view($user, $confirmation))->toBeFalse()
        ->and($policy->create($user))->toBeFalse()
        ->and($policy->update($user, $confirmation))->toBeFalse()
        ->and($policy->delete($user, $confirmation))->toBeFalse()
        ->and($policy->restore($user, $confirmation))->toBeFalse()
        ->and($policy->forceDelete($user, $confirmation))->toBeFalse();
});