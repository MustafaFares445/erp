<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class GenerateQuotationDocument implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $quotationId,
        public int $actorId,
    ) {}

    public function handle(): void
    {
        /** @var Quotation $quotation */
        $quotation = Quotation::query()
            ->with(['customer', 'lines.productVariant', 'lines.unit'])
            ->findOrFail($this->quotationId);

        if (! in_array($quotation->status, [QuotationStatus::Sent, QuotationStatus::Accepted], true)) {
            throw new DomainException('Only a sent or accepted quotation can generate its PDF.');
        }

        $pdf = Pdf::loadView('pdf.quotation', ['quotation' => $quotation]);
        $fileName = sprintf('%s-%s.pdf', $quotation->quotation_number, now()->format('Ymd-His-u'));

        $quotation->addMediaFromString($pdf->output())
            ->usingFileName($fileName)
            ->toMediaCollection('quotation-pdf');

        $actor = User::query()->find($this->actorId);
        $activity = activity()->performedOn($quotation);

        if ($actor instanceof User) {
            $activity->causedBy($actor);
        }

        $activity
            ->withProperties([
                'source_channel' => 'dashboard',
                'file_name' => $fileName,
                'version_count' => $quotation->getMedia('quotation-pdf')->count(),
            ])
            ->log('sales.quotation.pdf_generated');
    }
}
