<?php

declare(strict_types=1);

use App\Jobs\GenerateCreditNoteDocument;
use App\Jobs\GenerateInvoiceDocument;
use App\Models\CreditNote;
use App\Models\Invoice;
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
