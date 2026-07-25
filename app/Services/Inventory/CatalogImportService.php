<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\InventoryImportItemStatus;
use App\Enums\InventoryImportRunStatus;
use App\Jobs\ApplyCatalogImport;
use App\Jobs\ParseCatalogImport;
use App\Models\InventoryImportRun;
use App\Models\User;
use DateTimeInterface;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Throwable;

final readonly class CatalogImportService
{
    public function __construct(
        private CatalogImportValidator $validator,
        private CatalogImportApplicationService $applicationService,
        private CatalogImportReportService $reportService,
        private InventoryAlertService $inventoryAlertService,
    ) {}

    /** @throws DomainException */
    public function begin(UploadedFile $file, User $actor): InventoryImportRun
    {
        if (mb_strtolower($file->getClientOriginalExtension()) !== 'xlsx') {
            throw new DomainException(__('admin.inventory.import.errors.file_type'));
        }

        $path = $file->store('catalog-imports', 'local');

        if (! is_string($path)) {
            throw new DomainException(__('admin.inventory.import.errors.store_failed'));
        }

        return $this->queueStoredFile($path, $actor);
    }

    /** @throws DomainException */
    public function queueStoredFile(string $path, User $actor): InventoryImportRun
    {
        if (! $this->isValidStoredPath($path) || ! Storage::disk('local')->exists($path)) {
            throw new DomainException(__('admin.inventory.import.errors.store_failed'));
        }

        $run = InventoryImportRun::query()->create([
            'file_path' => $path,
            'status' => InventoryImportRunStatus::Queued,
            'created_by' => $actor->getKey(),
        ]);

        ParseCatalogImport::dispatch($this->importRunId($run))->afterCommit();

        return $run;
    }

    public function writeTemplate(string $path): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $columns = $this->validator->templateColumns();
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($columns));
        $writer->addRow(Row::fromValues($this->exampleValues($columns)));
        $writer->close();
    }

    public function parse(InventoryImportRun $run): void
    {
        $current = $run->fresh() ?? $run;

        if (! in_array($current->status, [
            InventoryImportRunStatus::Queued,
            InventoryImportRunStatus::Parsing,
        ], true)) {
            throw new DomainException(__('admin.inventory.import.errors.invalid_state'));
        }

        $run->forceFill([
            'status' => InventoryImportRunStatus::Parsing,
            'failure_message' => null,
        ])->save();
        $run->items()->delete();
        $reader = new Reader;
        $opened = false;

        try {
            $reader->open(Storage::disk('local')->path($run->file_path));
            $opened = true;
            $this->parseFirstSheet($reader, $run);
        } catch (Throwable $throwable) {
            $this->markFailed($run, $throwable);

            throw $throwable;
        } finally {
            if ($opened) {
                $reader->close();
            }
        }
    }

    /** @throws DomainException */
    public function confirm(InventoryImportRun $run, User $actor): void
    {
        DB::transaction(function () use ($run, $actor): void {
            /** @var InventoryImportRun $locked */
            $locked = InventoryImportRun::query()->lockForUpdate()->findOrFail($run->getKey());

            if (
                ! $locked->status->canApply()
                || $locked->valid_rows < 1
                || ! $locked->items()->where('status', InventoryImportItemStatus::Valid->value)->exists()
            ) {
                throw new DomainException(__('admin.inventory.import.errors.not_ready'));
            }

            $locked->forceFill([
                'status' => InventoryImportRunStatus::Applying,
                'confirmed_by' => $actor->getKey(),
                'applying_at' => now(),
                'failure_message' => null,
            ])->save();

            ApplyCatalogImport::dispatch(
                $this->importRunId($locked),
                $this->userId($actor),
            )->afterCommit();
        }, attempts: 5);
    }

    public function apply(InventoryImportRun $run, User $actor): void
    {
        try {
            $this->applicationService->apply($run, $actor);
            $this->generateReports($run);
            $this->inventoryAlertService->syncImport($run->fresh() ?? $run);
        } catch (Throwable $throwable) {
            $this->markFailed($run, $throwable);

            throw $throwable;
        }
    }

    public function markFailed(InventoryImportRun $run, Throwable $throwable): void
    {
        $fresh = $run->fresh() ?? $run;

        if (in_array($fresh->status, [
            InventoryImportRunStatus::Confirmed,
            InventoryImportRunStatus::ConfirmedWithErrors,
        ], true)) {
            return;
        }

        $fresh->forceFill([
            'status' => InventoryImportRunStatus::Failed,
            'failure_message' => Str::limit($throwable->getMessage(), 2_000),
        ])->save();
        $this->inventoryAlertService->syncImport($fresh);
    }

    private function parseFirstSheet(Reader $reader, InventoryImportRun $run): void
    {
        $header = null;
        $rowNumber = 0;
        $totalRows = 0;
        $validRows = 0;
        $attributes = $this->validator->activeAttributes();

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rowNumber++;
                $values = $this->rowValues($row);

                if ($header === null) {
                    $header = array_map($this->normalizeHeader(...), $values);
                    $this->validator->assertRequiredColumns($header);

                    continue;
                }

                if ($this->isBlankRow($values)) {
                    continue;
                }

                $totalRows++;
                $payload = $this->rowPayload($header, $values);
                $errors = $this->validator->validate($payload, $attributes);
                $isValid = $errors === [];
                $validRows += $isValid ? 1 : 0;

                $run->items()->create([
                    'row_number' => $rowNumber,
                    'idempotency_key' => hash('sha256', $this->importRunId($run).":{$rowNumber}"),
                    'payload' => $payload,
                    'errors' => $isValid ? null : $errors,
                    'status' => $isValid
                        ? InventoryImportItemStatus::Valid
                        : InventoryImportItemStatus::Invalid,
                ]);
            }

            break;
        }

        $this->finishParsing($run, $totalRows, $validRows);
    }

    private function finishParsing(InventoryImportRun $run, int $totalRows, int $validRows): void
    {
        $failedRows = $totalRows - $validRows;
        $status = match (true) {
            $validRows === 0 => InventoryImportRunStatus::Invalid,
            $failedRows > 0 => InventoryImportRunStatus::ReadyWithErrors,
            default => InventoryImportRunStatus::Ready,
        };

        $run->forceFill([
            'status' => $status,
            'total_rows' => $totalRows,
            'valid_rows' => $validRows,
            'failed_rows' => $failedRows,
            'rejected_rows' => $failedRows,
        ])->save();
        $this->inventoryAlertService->syncImport($run);
    }

    private function generateReports(InventoryImportRun $run): void
    {
        $fresh = $run->fresh() ?? $run;

        if (! in_array($fresh->status, [
            InventoryImportRunStatus::Confirmed,
            InventoryImportRunStatus::ConfirmedWithErrors,
        ], true)) {
            return;
        }

        try {
            $fresh->forceFill($this->reportService->generate($fresh))->save();
        } catch (Throwable $throwable) {
            $fresh->forceFill([
                'failure_message' => 'Report generation failed: '.Str::limit($throwable->getMessage(), 1_000),
            ])->save();
        }
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function exampleValues(array $columns): array
    {
        $example = [
            'sku' => 'SKU-001',
            'product_name' => 'Product English',
            'variant_name' => 'Variant English',
            'product_status' => 'active',
            'unit_symbol' => 'EA',
            'unit_name' => 'Each',
            'allows_decimal' => 'false',
            'track_serials' => 'false',
            'track_expiry' => 'false',
            'cost_price' => '10.00',
            'min_price' => '11.00',
            'markup_percent' => '25.00',
            'currency_code' => 'USD',
        ];

        return array_map(
            static fn (string $column): string => $example[$column] ?? '',
            $columns,
        );
    }

    /** @return list<string> */
    private function rowValues(Row $row): array
    {
        return array_values(array_map(
            fn (Cell $cell): string => $this->cellValue($cell->getValue()),
            $row->getCells(),
        ));
    }

    private function cellValue(mixed $value): string
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_string($value) => mb_trim($value),
            is_int($value), is_float($value) => mb_trim((string) $value),
            is_bool($value) => $value ? 'true' : 'false',
            default => '',
        };
    }

    /** @param list<string> $values */
    private function isBlankRow(array $values): bool
    {
        return collect($values)->every(fn (string $value): bool => $value === '');
    }

    private function normalizeHeader(string $value): string
    {
        return Str::snake(mb_strtolower(mb_trim($value)));
    }

    /**
     * @param  list<string>  $header
     * @param  list<string>  $values
     * @return array<string, string>
     */
    private function rowPayload(array $header, array $values): array
    {
        $payload = [];

        foreach ($header as $index => $column) {
            $value = $values[$index] ?? '';

            if ($value !== '') {
                $payload[$column] = $value;
            }
        }

        return $payload;
    }

    private function importRunId(InventoryImportRun $run): int
    {
        $key = $run->getKey();

        if (! is_int($key)) {
            throw new LogicException('Inventory import runs must use integer identifiers.');
        }

        return $key;
    }

    private function userId(User $user): int
    {
        $key = $user->getKey();

        if (! is_int($key)) {
            throw new LogicException('Inventory import actors must use integer identifiers.');
        }

        return $key;
    }

    private function isValidStoredPath(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);

        return Str::startsWith($normalized, 'catalog-imports/')
            && ! Str::contains($normalized, '/../')
            && mb_strtolower(pathinfo($normalized, PATHINFO_EXTENSION)) === 'xlsx';
    }
}
