<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class GenerateInvoiceDocument implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $invoiceId,
        public int $actorId,
    ) {}

    public function handle(): void
    {
        /** @var Invoice $invoice */
        $invoice = Invoice::query()
            ->with([
                'customer',
                'order',
                'inventoryOperation',
                'paymentTerm',
                'lines.productVariant',
            ])
            ->findOrFail($this->invoiceId);

        if (! $invoice->isIssued()) {
            throw new DomainException('Only an issued invoice can generate its final PDF.');
        }

        $pdf = Pdf::loadView('pdf.invoice', ['invoice' => $invoice]);
        $fileName = sprintf('%s-%s.pdf', $invoice->invoice_number, now()->format('Ymd-His-u'));

        $invoice->addMediaFromString($pdf->output())
            ->usingFileName($fileName)
            ->toMediaCollection('invoice-pdf');

        $actor = User::query()->find($this->actorId);
        $activity = activity()->performedOn($invoice);

        if ($actor instanceof User) {
            $activity->causedBy($actor);
        }

        $activity
            ->withProperties([
                'source_channel' => 'dashboard',
                'file_name' => $fileName,
                'version_count' => $invoice->getMedia('invoice-pdf')->count(),
            ])
            ->log('sales.invoice.pdf_generated');
    }
}
