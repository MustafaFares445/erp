<?php

declare(strict_types=1);

use App\Enums\CreditNoteStatus;
use App\Models\CreditNote;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Refund;
use App\Models\SalesSetting;
use App\Models\TaxRecognitionEntry;
use App\Models\User;
use App\Services\Accounting\RefundService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('covers refund source credit note and invoice mismatch guards', function (): void {
    $service = app(RefundService::class);
    $method = new ReflectionMethod(RefundService::class, 'assertSourceMatchesCustomer');

    $customer = CustomerProfile::factory()->create();
    $otherCustomer = CustomerProfile::factory()->create();

    $credit = new CreditNote;
    $credit->forceFill([
        'customer_id' => $otherCustomer->getKey(),
        'status' => CreditNoteStatus::Confirmed,
        'reversed_at' => null,
    ]);

    $refund = new Refund;
    $refund->forceFill([
        'customer_id' => $customer->getKey(),
        'invoice_id' => null,
    ]);
    $refund->setRelation('creditNote', $credit);
    $refund->setRelation('invoice', null);

    expect(fn (): mixed => $method->invoke($service, $refund))
        ->toThrow(DomainException::class, 'confirmed credit for the same customer');

    $credit->forceFill([
        'customer_id' => $customer->getKey(),
        'status' => CreditNoteStatus::Draft,
        'reversed_at' => null,
    ]);
    expect(fn (): mixed => $method->invoke($service, $refund))
        ->toThrow(DomainException::class, 'confirmed credit for the same customer');

    $credit->forceFill([
        'customer_id' => $customer->getKey(),
        'status' => CreditNoteStatus::Confirmed,
        'reversed_at' => now(),
    ]);
    expect(fn (): mixed => $method->invoke($service, $refund))
        ->toThrow(DomainException::class, 'confirmed credit for the same customer');

    $credit->forceFill([
        'customer_id' => $customer->getKey(),
        'status' => CreditNoteStatus::Confirmed,
        'reversed_at' => null,
        'invoice_id' => 42,
    ]);
    $refund->forceFill(['invoice_id' => 43]);

    expect(fn (): mixed => $method->invoke($service, $refund))
        ->toThrow(DomainException::class, 'refund invoice must match');

    $invoice = new Invoice;
    $invoice->forceFill(['customer_id' => $otherCustomer->getKey()]);

    $refund->unsetRelation('creditNote');
    $refund->setRelation('creditNote', null);
    $refund->setRelation('invoice', $invoice);
    $refund->forceFill(['customer_id' => $customer->getKey()]);

    expect(fn (): mixed => $method->invoke($service, $refund))
        ->toThrow(DomainException::class, 'same customer');
});

it('covers refund proportional minor boundary calculations', function (): void {
    $service = app(RefundService::class);
    $method = new ReflectionMethod(RefundService::class, 'proportionalMinor');

    expect($method->invoke($service, 0, 100, 20))->toBe(0)
        ->and($method->invoke($service, 10, 0, 20))->toBe(0)
        ->and($method->invoke($service, 10, 100, 0))->toBe(0)
        ->and($method->invoke($service, 100, 100, 20))->toBe(20)
        ->and($method->invoke($service, 50, 100, 20))->toBe(10);
});
it('covers refund tax allocation skips for zero source amounts zero tax and exhausted refund amount', function (): void {
    $service = app(RefundService::class);
    $method = new ReflectionMethod(RefundService::class, 'refundTaxMinor');
    $invoice = Invoice::factory()->create();

    $refund = new Refund;
    $refund->forceFill(['amount' => '10.00']);

    $sources = new Collection([
        (new TaxRecognitionEntry)->forceFill([
            'payment_amount' => '0.00',
            'recognised_tax_amount' => '1.00',
        ]),
        (new TaxRecognitionEntry)->forceFill([
            'payment_amount' => '10.00',
            'recognised_tax_amount' => '0.00',
        ]),
    ]);

    expect($method->invoke($service, $refund, $invoice, $sources))->toBe(0);

    $refund->forceFill(['amount' => '0.00']);
    $sources = new Collection([
        (new TaxRecognitionEntry)->forceFill([
            'payment_amount' => '10.00',
            'recognised_tax_amount' => '1.00',
        ]),
    ]);

    expect($method->invoke($service, $refund, $invoice, $sources))->toBe(0);
});

it('returns before tax unrecognition when the invoice has no recognised tax sources', function (): void {
    $service = app(RefundService::class);
    $invoice = Invoice::factory()->create();
    $refund = Refund::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'credit_note_id' => null,
        'amount' => '10.00',
    ]);
    $refund->setRelation('invoice', $invoice);

    $method = new ReflectionMethod(RefundService::class, 'unrecogniseTaxWhenRequired');

    expect($method->invoke(
        $service,
        User::factory()->admin()->create(),
        $refund,
        SalesSetting::current(),
    ))->toBeNull();
});
