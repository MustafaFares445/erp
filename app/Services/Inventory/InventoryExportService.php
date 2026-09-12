<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\InventoryExportType;
use App\Enums\InventoryPermission;
use App\Enums\InventoryReportType;
use App\Models\DocumentExport;
use App\Models\InventoryExport;
use App\Models\User;
use App\Services\Exports\DocumentExportService;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use LogicException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

final readonly class InventoryExportService
{
    public function __construct(
        private InventoryReportService $inventoryReportService,
        private InventoryReportFormatter $inventoryReportFormatter,
        private DocumentExportService $documentExportService,
    ) {}

    /** @param array<string, mixed> $filters @throws DomainException */
    public function request(string $type, array $filters, User $actor): DocumentExport
    {
        $exportType = InventoryExportType::tryFrom($type);

        if (! $exportType instanceof InventoryExportType) {
            throw new DomainException(__('admin.inventory.export.errors.invalid_type'));
        }

        $this->assertCanExport($actor, $exportType);
        $filters = $this->normalizeFilters($exportType, $filters);

        $export = $this->documentExportService->request(
            module: 'inventory',
            type: $exportType->value,
            format: 'xlsx',
            parameters: ['filters' => $filters],
            actor: $actor,
        );

        activity()
            ->performedOn($export)
            ->causedBy($actor)
            ->withChanges([
                'attributes' => ['type' => $exportType->value, 'filters' => $filters],
            ])
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log('inventory.export.requested');

        return $export;
    }

    /**
     * Compatibility entry point for old retained `inventory_exports` records.
     * New requests always use DocumentExport and GenerateDocumentExport.
     */
    public function generate(DocumentExport|InventoryExport $export): void
    {
        if ($export instanceof DocumentExport) {
            $this->documentExportService->generate($export);

            return;
        }

        $exportType = $this->exportType($export);
        $actor = $export->createdBy;

        if (! $actor instanceof User) {
            throw new DomainException(__('admin.inventory.export.errors.unauthorized'));
        }

        $this->assertCanExport($actor, $exportType);
        $export->forceFill(['status' => 'processing', 'failure_reason' => null])->save();

        $path = sprintf('inventory-exports/%d.xlsx', $this->exportId($export));

        try {
            $this->writeWorkbook(
                path: $path,
                exportType: $exportType,
                filters: $this->filters($export),
                includePricing: $actor->can(InventoryPermission::PricingView->value),
            );

            $export->forceFill([
                'status' => 'completed',
                'file_path' => $path,
                'completed_at' => now(),
            ])->save();
        } catch (Throwable $throwable) {
            Storage::disk('local')->delete($path);
            $export->forceFill([
                'status' => 'failed',
                'failure_reason' => $throwable->getMessage(),
            ])->save();

            throw $throwable;
        }
    }

    public function authorize(DocumentExport $export, User $actor): void
    {
        if ($export->module !== 'inventory') {
            throw new DomainException(__('admin.inventory.export.errors.unauthorized'));
        }

        $this->assertCanExport($actor, $this->exportType($export));
    }

    /** @return array{path: string, row_count: int} */
    public function write(DocumentExport $export): array
    {
        $actor = $export->createdBy;
        if (! $actor instanceof User) {
            throw new DomainException(__('admin.inventory.export.errors.unauthorized'));
        }

        $exportType = $this->exportType($export);
        $this->assertCanExport($actor, $exportType);
        $path = sprintf('inventory-exports/document-%d.xlsx', $this->exportId($export));
        $rowCount = $this->writeWorkbook(
            path: $path,
            exportType: $exportType,
            filters: $this->filters($export),
            includePricing: $actor->can(InventoryPermission::PricingView->value),
        );

        return ['path' => $path, 'row_count' => $rowCount];
    }

    /** @throws DomainException */
    public function download(DocumentExport|InventoryExport $export, User $actor): BinaryFileResponse
    {
        if ($export instanceof DocumentExport) {
            return $this->documentExportService->download($export, $actor);
        }

        $this->assertCanExport($actor, $this->exportType($export));

        if (
            $export->status !== 'completed'
            || $export->file_path === null
            || ! Storage::disk('local')->exists($export->file_path)
        ) {
            throw new DomainException(__('admin.inventory.export.errors.not_ready'));
        }

        return response()->download(
            Storage::disk('local')->path($export->file_path),
            sprintf('%s-%d.xlsx', $export->type, $this->exportId($export)),
        );
    }

    /** @param array<string, mixed> $filters */
    private function writeWorkbook(
        string $path,
        InventoryExportType $exportType,
        array $filters,
        bool $includePricing,
    ): int {
        $writer = new Writer;

        try {
            $absolutePath = Storage::disk('local')->path($path);
            $directory = dirname($absolutePath);

            if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
                throw new LogicException('Unable to create the private export directory.');
            }

            $writer->openToFile($absolutePath);
            $rowCount = $this->writeReports($writer, $exportType, $filters, $includePricing);
            $writer->close();

            return $rowCount;
        } catch (Throwable $throwable) {
            $this->closeAfterFailure($writer);
            Storage::disk('local')->delete($path);

            throw $throwable;
        }
    }

    /** @param array<string, mixed> $filters */
    private function writeReports(Writer $writer, InventoryExportType $exportType, array $filters, bool $includePricing): int
    {
        $rowCount = 0;

        foreach ($exportType->reports() as $index => $reportType) {
            $sheet = $index === 0
                ? $writer->getCurrentSheet()
                : $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName($this->sheetName($reportType));
            $writer->addRow(Row::fromValues(
                $this->inventoryReportFormatter->headings($reportType, $includePricing),
            ));
            $rowCount += $this->writeReportRows($writer, $reportType, $filters, $includePricing);
        }

        return $rowCount;
    }

    /** @param array<string, mixed> $filters */
    private function writeReportRows(Writer $writer, InventoryReportType $reportType, array $filters, bool $includePricing): int
    {
        $rowCount = 0;

        $this->inventoryReportService
            ->query($reportType, $this->filtersForReport($reportType, $filters))
            ->chunkById(500, function (Collection $records) use ($writer, $reportType, $includePricing, &$rowCount): void {
                foreach ($records as $record) {
                    $writer->addRow(Row::fromValues(
                        $this->inventoryReportFormatter->values($reportType, $record, $includePricing),
                    ));
                    $rowCount++;
                }
            });

        return $rowCount;
    }

    /** @throws DomainException */
    private function assertCanExport(User $actor, InventoryExportType $exportType): void
    {
        if (! $actor->can(InventoryPermission::Export->value)) {
            throw new DomainException(__('admin.inventory.export.errors.unauthorized'));
        }

        foreach ($exportType->reports() as $reportType) {
            $this->inventoryReportService->authorizeView($actor, $reportType);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, bool|int|string>
     */
    private function normalizeFilters(InventoryExportType $exportType, array $filters): array
    {
        if ($exportType === InventoryExportType::ImportResults) {
            return $this->normalizeImportFilters($filters);
        }

        $normalized = [];

        foreach ($exportType->reports() as $reportType) {
            $normalized = [
                ...$normalized,
                ...$this->inventoryReportService->normalizeFilters($reportType, $filters),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, bool|int|string>
     */
    private function normalizeImportFilters(array $filters): array
    {
        $normalized = $this->inventoryReportService->normalizeFilters(
            InventoryReportType::ImportResults,
            $filters,
        );

        foreach ([
            'run_status' => InventoryReportType::ImportRuns,
            'item_status' => InventoryReportType::ImportResults,
        ] as $key => $reportType) {
            $status = $this->inventoryReportService->normalizeFilters(
                $reportType,
                ['status' => $filters[$key] ?? null],
            )['status'] ?? null;

            if (is_string($status)) {
                $normalized[$key] = $status;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function filtersForReport(InventoryReportType $reportType, array $filters): array
    {
        $statusKey = match ($reportType) {
            InventoryReportType::ImportRuns => 'run_status',
            InventoryReportType::ImportResults => 'item_status',
            default => null,
        };

        if ($statusKey !== null && isset($filters[$statusKey])) {
            $filters['status'] = $filters[$statusKey];
        }

        unset($filters['run_status'], $filters['item_status']);

        return $filters;
    }

    private function exportType(DocumentExport|InventoryExport $export): InventoryExportType
    {
        $type = InventoryExportType::tryFrom($export->type);

        if (! $type instanceof InventoryExportType) {
            throw new DomainException(__('admin.inventory.export.errors.invalid_type'));
        }

        return $type;
    }

    private function sheetName(InventoryReportType $reportType): string
    {
        return match ($reportType) {
            InventoryReportType::Catalog => 'Catalog',
            InventoryReportType::StockLevels => 'Stock Levels',
            InventoryReportType::Movements => 'Movements',
            InventoryReportType::Devices => 'Devices',
            InventoryReportType::ExpiryLots => 'Expiry Lots',
            InventoryReportType::ConditionChanges => 'Condition Changes',
            InventoryReportType::CountVariance => 'Count Variance',
            InventoryReportType::SupplierComparison => 'Suppliers',
            InventoryReportType::PriceHistory => 'Price History',
            InventoryReportType::PricingTiers => 'Pricing Tiers',
            InventoryReportType::CustomerAssignments => 'Assignments',
            InventoryReportType::FloorOverrides => 'Floor Overrides',
            InventoryReportType::ImportRuns => 'Import Runs',
            InventoryReportType::ImportResults => 'Import Results',
        };
    }

    private function closeAfterFailure(Writer $writer): void
    {
        try {
            $writer->close();
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }

    private function exportId(DocumentExport|InventoryExport $export): int
    {
        $key = $export->getKey();

        if (! is_int($key)) {
            throw new LogicException('Inventory exports must use integer identifiers.');
        }

        return $key;
    }

    /** @return array<string, mixed> */
    private function filters(DocumentExport|InventoryExport $export): array
    {
        $rawFilters = $export instanceof DocumentExport
            ? (is_array($export->parameters) ? ($export->parameters['filters'] ?? []) : [])
            : $export->filters;

        if (! is_array($rawFilters)) {
            return [];
        }

        $filters = [];

        foreach ($rawFilters as $key => $value) {
            if (is_string($key)) {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }
}
