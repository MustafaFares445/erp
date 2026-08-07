<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Enums\EmployeeReportType;
use App\Jobs\GenerateEmployeeReportExport;
use App\Models\CustomerVisit;
use App\Models\EmployeePerformanceScore;
use App\Models\EmployeeReportExport;
use App\Models\EmployeeSalaryCalculation;
use App\Models\PlanTask;
use App\Models\SalesPlan;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use LogicException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Queued XLSX export for a single {@see EmployeeReportType}, mirroring
 * `InventoryExportService` (R-008).
 */
final readonly class EmployeeReportExportService
{
    public function __construct(
        private AuditLogger $auditLogger,
        private EmployeeReportService $employeeReportService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     *
     * @throws DomainException
     */
    public function request(EmployeeReportType $type, array $filters, User $actor): EmployeeReportExport
    {
        $this->employeeReportService->authorizeView($actor, $type);
        $filters = $this->employeeReportService->normalizeFilters($filters);

        $export = EmployeeReportExport::query()->create([
            'type' => $type->value,
            'filters' => $filters,
            'status' => 'queued',
            'created_by' => $actor->getKey(),
        ]);

        $this->auditLogger->log(
            action: 'employee_report.export_requested',
            entity: $export,
            newValues: ['type' => $type->value, 'filters' => $filters],
            actor: $actor,
        );

        GenerateEmployeeReportExport::dispatch($this->exportId($export));

        return $export;
    }

    public function generate(EmployeeReportExport $export): void
    {
        $type = $this->reportType($export);
        $actor = $export->createdBy;

        if (! $actor instanceof User) {
            throw new DomainException(__('admin.employees.errors.report_unauthorized'));
        }

        $this->employeeReportService->authorizeView($actor, $type);
        $export->forceFill(['status' => 'processing', 'failure_reason' => null])->save();

        $path = sprintf('employee-reports/%d.xlsx', $this->exportId($export));
        $writer = new Writer;

        try {
            $absolutePath = Storage::disk('local')->path($path);
            $directory = dirname($absolutePath);

            if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
                throw new LogicException('Unable to create the private export directory.');
            }

            $writer->openToFile($absolutePath);
            $writer->addRow(Row::fromValues($this->headings($type)));
            $this->writeRows($writer, $type, $this->filters($export));
            $writer->close();

            $export->forceFill([
                'status' => 'completed',
                'file_path' => $path,
                'completed_at' => now(),
            ])->save();
        } catch (Throwable $throwable) {
            $this->closeAfterFailure($writer);
            Storage::disk('local')->delete($path);
            $export->forceFill([
                'status' => 'failed',
                'failure_reason' => $throwable->getMessage(),
            ])->save();

            throw $throwable;
        }
    }

    /** @throws DomainException */
    public function download(EmployeeReportExport $export, User $actor): BinaryFileResponse
    {
        $this->employeeReportService->authorizeView($actor, $this->reportType($export));

        if (
            $export->status !== 'completed'
            || $export->file_path === null
            || ! Storage::disk('local')->exists($export->file_path)
        ) {
            throw new DomainException(__('admin.employees.errors.report_export_not_ready'));
        }

        return response()->download(
            Storage::disk('local')->path($export->file_path),
            sprintf('%s-%d.xlsx', $export->type, $this->exportId($export)),
        );
    }

    /** @param array<string, mixed> $filters */
    private function writeRows(Writer $writer, EmployeeReportType $type, array $filters): void
    {
        $this->employeeReportService->query($type, $filters)->chunkById(
            500,
            function (Collection $records) use ($writer): void {
                foreach ($records as $record) {
                    $writer->addRow(Row::fromValues($this->values($record)));
                }
            },
        );
    }

    /** @return list<string> */
    private function headings(EmployeeReportType $type): array
    {
        return match ($type) {
            EmployeeReportType::PlanCompletion => ['Plan', 'Employee ID', 'Month', 'Total tasks', 'Completed tasks', 'Completion %'],
            EmployeeReportType::OverdueTasks => ['Task', 'Plan', 'Due date', 'Status'],
            EmployeeReportType::UnexecutedVisits => ['Employee ID', 'Customer ID', 'Status', 'Planned at'],
            EmployeeReportType::PerformanceByEmployee, EmployeeReportType::PerformanceByMonth => ['Employee ID', 'Plan', 'Total score', 'Task completion %', 'Calculated at'],
            EmployeeReportType::SalaryByEmployee, EmployeeReportType::SalaryByMonth => ['Employee ID', 'Plan', 'Payable base', 'Performance %', 'Bonus amount', 'Final salary', 'Status'],
        };
    }

    /** @return list<bool|float|int|string|null> */
    private function values(mixed $record): array
    {
        return match (true) {
            $record instanceof SalesPlan => [
                $record->name,
                $record->employee_id,
                $record->month->toDateString(),
                $record->tasks->count(),
                $record->tasks->where('status', 'Completed')->count(),
                $record->tasks->count() > 0
                    ? round($record->tasks->where('status', 'Completed')->count() / $record->tasks->count() * 100, 2)
                    : 0.0,
            ],
            $record instanceof PlanTask => [
                $record->title,
                $record->salesPlan?->name,
                $record->due_at->toDateString(),
                $record->status->value,
            ],
            $record instanceof CustomerVisit => [
                $record->employee_id,
                $record->customer_id,
                $record->status->value,
                $record->planned_at?->toDateTimeString(),
            ],
            $record instanceof EmployeePerformanceScore => [
                $record->employee_id,
                $record->salesPlan?->name,
                (float) $record->total_score,
                (float) $record->task_completion_percent,
                $record->calculated_at->toDateTimeString(),
            ],
            $record instanceof EmployeeSalaryCalculation => [
                $record->employee_id,
                $record->salesPlan?->name,
                (float) $record->payable_base,
                (float) $record->performance_percent,
                (float) $record->bonus_amount,
                (float) $record->final_salary,
                $record->status->value,
            ],
            default => [],
        };
    }

    private function reportType(EmployeeReportExport $export): EmployeeReportType
    {
        $type = EmployeeReportType::tryFrom($export->type);

        if (! $type instanceof EmployeeReportType) {
            throw new DomainException('Unknown employee report export type.');
        }

        return $type;
    }

    private function closeAfterFailure(Writer $writer): void
    {
        try {
            $writer->close();
            // @codeCoverageIgnoreStart
            // Reaching a second, closing-time failure requires the OpenSpout
            // writer itself to throw after the outer try block already failed —
            // not reachable without mocking a concrete, directly-instantiated
            // third-party writer.
        } catch (Throwable $throwable) {
            report($throwable);
        }

        // @codeCoverageIgnoreEnd
    }

    private function exportId(EmployeeReportExport $export): int
    {
        $key = $export->getKey();

        // @codeCoverageIgnoreStart
        // Unreachable in practice: `employee_report_exports.id` is an
        // auto-increment integer primary key, so Eloquent's getKey() is
        // always an int here. Guard kept only to satisfy static analysis.
        if (! is_int($key)) {
            throw new LogicException('Employee report exports must use integer identifiers.');
        }

        // @codeCoverageIgnoreEnd

        return $key;
    }

    /** @return array<string, mixed> */
    private function filters(EmployeeReportExport $export): array
    {
        if (! is_array($export->filters)) {
            return [];
        }

        $filters = [];

        foreach ($export->filters as $key => $value) {
            if (is_string($key)) {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }
}
