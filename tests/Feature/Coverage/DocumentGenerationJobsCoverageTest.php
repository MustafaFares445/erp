<?php

declare(strict_types=1);

use App\Enums\QuotationStatus;
use App\Jobs\GenerateCreditNoteDocument;
use App\Jobs\GenerateInvoiceDocument;
use App\Jobs\GeneratePackingListDocument;
use App\Jobs\GenerateQuotationDocument;
use App\Models\CreditNote;
use App\Models\InventoryOperation;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('generates and stores the final invoice PDF', function (): void {
    Storage::fake('local');
    $actor = User::factory()->create();
    $invoice = Invoice::factory()->create([
        'issued_at' => now(),
        'total_amount' => '125.50',
    ]);

    new GenerateInvoiceDocument($invoice->getKey(), $actor->getKey())->handle();

    $invoice->refresh();
    expect($invoice->getMedia('invoice-pdf'))->toHaveCount(1)
        ->and($invoice->getFirstMedia('invoice-pdf')?->file_name)
        ->toStartWith($invoice->invoice_number.'-');
});

it('generates and stores the confirmed credit-note PDF', function (): void {
    Storage::fake('local');
    $actor = User::factory()->create();
    $creditNote = CreditNote::factory()->create([
        'confirmed_at' => now(),
        'grand_total' => '75.25',
    ]);

    new GenerateCreditNoteDocument($creditNote->getKey(), $actor->getKey())->handle();

    $creditNote->refresh();
    expect($creditNote->getMedia('credit-note-pdf'))->toHaveCount(1)
        ->and($creditNote->getFirstMedia('credit-note-pdf')?->file_name)
        ->toStartWith($creditNote->credit_note_number.'-');
});

it('generates and stores the packing list PDF for a ready delivery', function (): void {
    Storage::fake('local');
    $actor = User::factory()->create();
    $delivery = InventoryOperation::factory()->delivery()->ready()->create();

    new GeneratePackingListDocument($delivery->getKey(), $actor->getKey())->handle();

    $delivery->refresh();
    expect($delivery->getMedia('packing-list-pdf'))->toHaveCount(1)
        ->and($delivery->getFirstMedia('packing-list-pdf')?->file_name)
        ->toStartWith($delivery->operation_number.'-');
});

it('refuses to generate a packing list for a receipt or a delivery that is not yet ready', function (): void {
    $actor = User::factory()->create();
    $receipt = InventoryOperation::factory()->receipt()->ready()->create();
    $draftDelivery = InventoryOperation::factory()->delivery()->draft()->create();

    expect(fn () => new GeneratePackingListDocument($receipt->getKey(), $actor->getKey())->handle())
        ->toThrow(DomainException::class)
        ->and(fn () => new GeneratePackingListDocument($draftDelivery->getKey(), $actor->getKey())->handle())
        ->toThrow(DomainException::class);
});

it('generates and stores the quotation PDF for a sent or accepted quotation', function (): void {
    Storage::fake('local');
    $actor = User::factory()->create();
    $quotation = Quotation::factory()->sent()->create();

    new GenerateQuotationDocument($quotation->getKey(), $actor->getKey())->handle();

    $quotation->refresh();
    expect($quotation->getMedia('quotation-pdf'))->toHaveCount(1)
        ->and($quotation->getFirstMedia('quotation-pdf')?->file_name)
        ->toStartWith($quotation->quotation_number.'-');
});

it('refuses to generate a quotation PDF for a draft quotation', function (): void {
    $actor = User::factory()->create();
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Draft]);

    expect(fn () => new GenerateQuotationDocument($quotation->getKey(), $actor->getKey())->handle())
        ->toThrow(DomainException::class);
});
