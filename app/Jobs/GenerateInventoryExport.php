<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\InventoryExport;
use App\Services\Inventory\InventoryExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class GenerateInventoryExport implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $inventoryExportId) {}

    public function handle(InventoryExportService $inventoryExportService): void
    {
        $export = InventoryExport::query()->findOrFail($this->inventoryExportId);
        $inventoryExportService->generate($export);
    }
}
