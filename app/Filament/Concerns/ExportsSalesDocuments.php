<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Enums\SalesPermission;
use App\Filament\Resources\DocumentExports\DocumentExportResource;
use App\Models\User;
use App\Services\Sales\SalesDocumentExportService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Permission-gated retained export requests for Sales list pages.
 *
 * The filtered record identifiers are snapshotted at request time so the
 * queued writer reproduces the exact visible selection even if table data or
 * filters change before the worker executes.
 *
 * @phpstan-require-extends ListRecords
 */
trait ExportsSalesDocuments
{
    private function salesDocumentExportAction(): Action
    {
        return Action::make('export_csv')
            ->label('Export CSV')
            ->icon('heroicon-o-arrow-down-tray')
            ->visible(fn (): bool => $this->canExportSalesDocuments())
            ->authorize(fn (): bool => $this->canExportSalesDocuments())
            ->action(function (): void {
                $this->requestSalesDocumentExport();
            });
    }

    private function canExportSalesDocuments(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->can(SalesPermission::Export->value);
    }

    private function requestSalesDocumentExport(): void
    {
        abort_unless($this->canExportSalesDocuments(), 403);

        /** @var Builder<Model>|null $query */
        $query = $this->getFilteredTableQuery();
        if (! $query instanceof Builder) {
            return;
        }

        $actor = auth()->user();
        if (! $actor instanceof User) {
            return;
        }

        $model = $query->getModel();
        $ids = $query
            ->pluck($model->getQualifiedKeyName())
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        $service = app(SalesDocumentExportService::class);
        $service->request(
            $service->typeForModel($model::class),
            $ids,
            [
                'resource' => static::class,
                'filters' => $this->tableFilters ?? [],
                'search' => $this->tableSearch ?? null,
            ],
            $actor,
        );

        Notification::make()
            ->success()
            ->title('Export queued')
            ->body('The CSV is being generated and retained for download.')
            ->actions([
                Action::make('view_exports')
                    ->label('View exports')
                    ->url(DocumentExportResource::getUrl()),
            ])
            ->send();
    }
}
