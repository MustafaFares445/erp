<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\DocumentExport;
use App\Services\Exports\DocumentExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class GenerateDocumentExport implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $documentExportId) {}

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        $releaseAfter = max(1, (int) config('document_exports.overlap_release_seconds', 30));
        $expireAfter = max(
            $releaseAfter + 1,
            (int) config('document_exports.overlap_lock_seconds', 3600),
        );

        return [
            new WithoutOverlapping('document-export:'.$this->documentExportId)
                ->releaseAfter($releaseAfter)
                ->expireAfter($expireAfter),
        ];
    }

    public function handle(DocumentExportService $documentExportService): void
    {
        $export = DocumentExport::query()->findOrFail($this->documentExportId);
        $documentExportService->generate($export);
    }
}
