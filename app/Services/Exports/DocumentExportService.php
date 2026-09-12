<?php

declare(strict_types=1);

namespace App\Services\Exports;

use App\Jobs\GenerateDocumentExport;
use App\Models\DocumentExport;
use App\Models\User;
use App\Services\Employees\EmployeeReportExportService;
use App\Services\Inventory\InventoryExportService;
use App\Services\Sales\SalesDocumentExportService;
use DomainException;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

final class DocumentExportService
{
    private const string FAILURE_REASON = 'Document export generation failed.';

    /** @var list<string> */
    private const array MODULES = ['inventory', 'employees', 'sales'];

    /** @var list<string> */
    private const array FORMATS = ['csv', 'xlsx'];

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function request(
        string $module,
        string $type,
        string $format,
        array $parameters,
        User $actor,
    ): DocumentExport {
        if (! in_array($module, self::MODULES, true)) {
            throw new DomainException('Unsupported document export module.');
        }

        if ($type === '') {
            throw new DomainException('Document export type is required.');
        }

        if (! in_array($format, self::FORMATS, true)) {
            throw new DomainException('Unsupported document export format.');
        }

        $retentionDays = max(1, (int) config('document_exports.retention_days', 7));

        $export = DocumentExport::query()->create([
            'module' => $module,
            'type' => $type,
            'format' => $format,
            'parameters' => $parameters,
            'row_count' => 0,
            'status' => 'queued',
            'created_by' => $actor->getKey(),
            'expires_at' => now()->addDays($retentionDays),
        ]);

        GenerateDocumentExport::dispatch($this->exportId($export));

        return $export;
    }

    public function generate(DocumentExport $export): void
    {
        if ($export->status === 'completed') {
            return;
        }

        if ($this->isExpired($export)) {
            $this->expire($export);

            return;
        }

        $export->forceFill([
            'status' => 'processing',
            'failure_reason' => null,
        ])->save();

        try {
            $result = match ($export->module) {
                'inventory' => app(InventoryExportService::class)->write($export),
                'employees' => app(EmployeeReportExportService::class)->write($export),
                'sales' => app(SalesDocumentExportService::class)->write($export),
                default => throw new DomainException('Unsupported document export module.'),
            };

            $export->forceFill([
                'status' => 'completed',
                'file_path' => $result['path'],
                'row_count' => $result['row_count'],
                'completed_at' => now(),
            ])->save();
        } catch (Throwable $throwable) {
            report($throwable);

            if (is_string($export->file_path) && $export->file_path !== '') {
                Storage::disk('local')->delete($export->file_path);
            }

            $export->forceFill([
                'status' => 'failed',
                'failure_reason' => self::FAILURE_REASON,
            ])->save();

            throw $throwable;
        }
    }

    /** @throws DomainException */
    public function download(DocumentExport $export, User $actor): BinaryFileResponse
    {
        $this->authorize($export, $actor);

        if ($this->isExpired($export)) {
            $this->expire($export);
            throw new DomainException('This document export has expired.');
        }

        if (
            $export->status !== 'completed'
            || $export->file_path === null
            || ! Storage::disk('local')->exists($export->file_path)
        ) {
            throw new DomainException('This document export is not ready for download.');
        }

        return response()->download(
            Storage::disk('local')->path($export->file_path),
            sprintf('%s-%d.%s', $export->type, $this->exportId($export), $export->format),
        );
    }

    public function authorize(DocumentExport $export, User $actor): void
    {
        if ((int) $export->created_by !== (int) $actor->getKey()) {
            throw new DomainException('You are not authorized to access this document export.');
        }

        match ($export->module) {
            'inventory' => app(InventoryExportService::class)->authorize($export, $actor),
            'employees' => app(EmployeeReportExportService::class)->authorize($export, $actor),
            'sales' => app(SalesDocumentExportService::class)->authorize($export, $actor),
            default => throw new DomainException('Unsupported document export module.'),
        };
    }

    public function cleanupExpired(): int
    {
        $cleaned = 0;

        DocumentExport::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->where('status', '!=', 'expired')
            ->orderBy('id')
            ->eachById(function (DocumentExport $export) use (&$cleaned): void {
                $this->expire($export);
                $cleaned++;
            }, 200);

        return $cleaned;
    }

    private function expire(DocumentExport $export): void
    {
        if (is_string($export->file_path) && $export->file_path !== '') {
            Storage::disk('local')->delete($export->file_path);
        }

        $export->forceFill([
            'status' => 'expired',
            'file_path' => null,
        ])->save();
    }

    private function isExpired(DocumentExport $export): bool
    {
        return $export->expires_at !== null && $export->expires_at->isPast();
    }

    private function exportId(DocumentExport $export): int
    {
        $key = $export->getKey();

        if (! is_int($key)) {
            throw new LogicException('Document exports must use integer identifiers.');
        }

        return $key;
    }
}
