<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\InventoryImportRun;
use App\Services\Inventory\CatalogImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ParseCatalogImport implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $importRunId) {}

    public function handle(CatalogImportService $catalogImportService): void
    {
        $run = InventoryImportRun::query()->findOrFail($this->importRunId);
        $catalogImportService->parse($run);
    }
}
