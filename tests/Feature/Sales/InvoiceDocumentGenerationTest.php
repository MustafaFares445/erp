<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the invoice PDF template with a backed invoice status enum', function (): void {
    $invoice = Invoice::factory()->create([
        'status' => InvoiceStatus::Issued,
    ]);

    $html = view('pdf.invoice', ['invoice' => $invoice->load(['customer', 'order', 'lines.productVariant'])])->render();

    expect($html)->toContain('<strong>Payment status</strong></td><td>issued</td>');
});
