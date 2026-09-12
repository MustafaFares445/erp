<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\DocumentExport;
use App\Services\Exports\DocumentExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class GenerateDocumentExport implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $documentExportId) {}

    public function handle(DocumentExportService $documentExportService): void
    {
        $export = DocumentExport::query()->findOrFail($this->documentExportId);
        $documentExportService->generate($export);
    }
}
