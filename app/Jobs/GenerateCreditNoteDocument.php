<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CreditNote;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class GenerateCreditNoteDocument implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $creditNoteId,
        public int $actorId,
    ) {}

    public function handle(): void
    {
        /** @var CreditNote $creditNote */
        $creditNote = CreditNote::query()
            ->with(['customer', 'invoice', 'lines.invoiceLine'])
            ->findOrFail($this->creditNoteId);

        if (! $creditNote->isConfirmed()) {
            throw new DomainException('Only a confirmed credit note can generate its PDF.');
        }

        $pdf = Pdf::loadView('pdf.credit_note', ['creditNote' => $creditNote]);
        $fileName = sprintf('%s-%s.pdf', $creditNote->credit_note_number, now()->format('Ymd-His-u'));

        $creditNote->addMediaFromString($pdf->output())
            ->usingFileName($fileName)
            ->toMediaCollection('credit-note-pdf');

        $actor = User::query()->find($this->actorId);
        $activity = activity()->performedOn($creditNote);

        if ($actor instanceof User) {
            $activity->causedBy($actor);
        }

        $activity
            ->withProperties([
                'source_channel' => 'dashboard',
                'file_name' => $fileName,
                'version_count' => $creditNote->getMedia('credit-note-pdf')->count(),
            ])
            ->log('sales.credit_note.pdf_generated');
    }
}
