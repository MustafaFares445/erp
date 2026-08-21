<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\InventoryImportRun;
use App\Models\User;
use App\Services\Inventory\CatalogImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ApplyCatalogImport implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $importRunId,
        public int $actorId,
    ) {}

    public function handle(CatalogImportService $catalogImportService): void
    {
        $run = InventoryImportRun::query()->findOrFail($this->importRunId);
        $actor = User::query()->findOrFail($this->actorId);
        $catalogImportService->apply($run, $actor);
    }

    public function failed(?Throwable $throwable): void
    {
        if (! $throwable instanceof Throwable) {
            return;
        }

        $run = InventoryImportRun::query()->find($this->importRunId);

        if ($run instanceof InventoryImportRun) {
            app(CatalogImportService::class)->markFailed($run, $throwable);
        }
    }
}
