<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Enums\SalesPermission;
use App\Models\User;
use App\Services\Inventory\InventoryExportService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Permission-gated CSV export for a Sales-module list page (WP-2.8).
 *
 * Records an `activity()` log entry for every export — who exported, when,
 * and which table filters/search were active — mirroring
 * {@see InventoryExportService::request()}'s audit
 * trail rather than inventing a second logging mechanism. Unlike that
 * service, this export is synchronous and unqueued: each of the five
 * documents this trait is used for (invoices, payments, quotations, credit
 * notes, orders) lists at most a few thousand rows, so the queued-export
 * machinery {@see InventoryExportService} exists for
 * is not needed here, and no export record is persisted.
 *
 * The query exported is always {@see self::getFilteredTableQuery()} — the
 * table's own currently-filtered/searched query — so an export can never
 * silently widen to the full unfiltered table.
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
            ->action(fn (): StreamedResponse => $this->exportSalesDocumentsCsv());
    }

    private function canExportSalesDocuments(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->can(SalesPermission::Export->value);
    }

    private function exportSalesDocumentsCsv(): StreamedResponse
    {
        abort_unless($this->canExportSalesDocuments(), 403);

        /** @var Builder<Model>|null $query */
        $query = $this->getFilteredTableQuery();
        $records = $query instanceof Builder ? $query->get() : collect();

        $actor = auth()->user();

        if ($actor instanceof User) {
            activity()
                ->causedBy($actor)
                ->withProperties([
                    'resource' => static::class,
                    'filters' => $this->tableFilters ?? [],
                    'search' => $this->tableSearch ?? null,
                    'record_count' => $records->count(),
                    'ip_address' => request()->ip(),
                ])
                ->log($this->salesDocumentExportLogName());
        }

        $headings = $this->salesDocumentExportHeadings();

        return response()->streamDownload(function () use ($headings, $records): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, $headings, escape: '\\');

            foreach ($records as $record) {
                if (! $record instanceof Model) {
                    continue;
                }

                fputcsv($handle, $this->salesDocumentExportRow($record), escape: '\\');
            }

            fclose($handle);
        }, $this->salesDocumentExportFilename(), ['Content-Type' => 'text/csv']);
    }

    /** @return list<string> */
    abstract private function salesDocumentExportHeadings(): array;

    /** @return list<bool|float|int|string|null> */
    abstract private function salesDocumentExportRow(Model $record): array;

    abstract private function salesDocumentExportFilename(): string;

    abstract private function salesDocumentExportLogName(): string;
}
