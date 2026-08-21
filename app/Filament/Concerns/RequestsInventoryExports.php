<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Enums\InventoryExportType;
use App\Enums\InventoryPermission;
use App\Enums\InventoryReportType;
use App\Filament\Resources\InventoryExports\Schemas\InventoryExportRequestSchema;
use App\Models\User;
use App\Services\Inventory\InventoryExportService;
use App\Services\Inventory\InventoryReportService;
use Filament\Actions\Action;

trait RequestsInventoryExports
{
    private function inventoryExportAction(InventoryExportType $type): Action
    {
        return Action::make('request_'.$type->value)
            ->label('Export '.$type->primaryReport()->label())
            ->form(InventoryExportRequestSchema::make($type))
            ->visible(fn (): bool => $this->canRequestInventoryExport($type))
            ->action(
                /** @param array<string, mixed> $data */
                function (array $data) use ($type): void {
                    $actor = auth()->user();

                    if ($actor instanceof User) {
                        app(InventoryExportService::class)->request(
                            $type->value,
                            $this->inventoryExportFormData($data),
                            $actor,
                        );
                    }
                },
            );
    }

    private function canRequestInventoryExport(InventoryExportType $type): bool
    {
        $actor = auth()->user();

        if (! $actor instanceof User || ! $actor->can(InventoryPermission::Export->value)) {
            return false;
        }

        $reportService = app(InventoryReportService::class);

        return collect($type->reports())->every(
            fn (InventoryReportType $report): bool => $reportService->canView($actor, $report),
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    private function inventoryExportFormData(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
