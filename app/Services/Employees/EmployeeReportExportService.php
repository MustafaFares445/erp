<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Enums\EmployeeReportType;
use App\Models\CustomerVisit;
use App\Models\DocumentExport;
use App\Models\EmployeePerformanceScore;
use App\Models\EmployeeReportExport;
use App\Models\EmployeeSalaryCalculation;
use App\Models\PlanTask;
use App\Models\SalesPlan;
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

final readonly class EmployeeReportExportService
{
    public function __construct(
        private EmployeeReportService $employeeReportService,
        private DocumentExportService $documentExportService,
    ) {}

    /**
     * @param array<string, mixed> $filters
     * @throws DomainException
     */
    public function request(EmployeeReportType $type, array $filters, User $actor): DocumentExport
    {
        $this->employeeReportService->authorizeView($actor, $type);
        $filters = $this->employeeReportService->normalizeFilters($filters);

        $export = $this->documentExportService->request(
            module: 'employees',
            type: $type->value,
            format: 'xlsx',
            parameters: ['filters' => $filters],
            actor: $actor,
        );

        activity()
            ->performedOn($export)
            ->causedBy($actor)
            ->withChanges([
                'attributes' => ['type' => $type->value, 'filters' => $filters],
            ])
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log('employee_report.export_requested');

        return $export;
    }

    /**
     * Compatibility entry point for old retained `employee_report_exports` records.
     * New requests always use DocumentExport and GenerateDocumentExport.
     */
    public function generate(DocumentExport|EmployeeReportExport $export): void
    {
        if ($export instanceof DocumentExport) {
            $this->documentExportService->generate($export);

            return;
        }

        $type = $this->reportType($export);
        $actor = $export->createdBy;

        if (! $actor instanceof User) {
            throw new DomainException(__('admin.employees.errors.report_unauthorized'));
        }

        $this->employeeReportService->authorizeView($actor, $type);
        $export->forceFill(['status' => 'processing', 'failure_reason' => null])->save();
        $path = sprintf('employee-reports/%d.xlsx', $this->exportId($export));

        try {
            $this->writeWorkbook($path, $type, $this->filters($export));
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
        if ($export->module !== 'employees') {
            throw new DomainException(__('admin.employees.errors.report_unauthorized'));
        }

        $this->employeeReportService->authorizeView($actor, $this->reportType($export));
    }

    /** @return array{path: string, row_count: int} */
    public function write(DocumentExport $export): array
    {
        $actor = $export->createdBy;
        if (! $actor instanceof User) {
            throw new DomainException(__('admin.employees.errors.report_unauthorized'));
        }

        $type = $this->reportType($export);
        $this->employeeReportService->authorizeView($actor, $type);
        $path = sprintf('employee-reports/document-%d.xlsx', $this->exportId($export));
        $rowCount = $this->writeWorkbook($path, $type, $this->filters($export));

        return ['path' => $path, 'row_count' => $rowCount];
    }

    /** @throws DomainException */
    public function download(DocumentExport|EmployeeReportExport $export, User $actor): BinaryFileResponse
    {
        if ($export instanceof DocumentExport) {
            return $this->documentExportService->download($export, $actor);
        }

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
    private function writeWorkbook(string $path, EmployeeReportType $type, array $filters): int
    {
        $writer = new Writer;

        try {
            $absolutePath = Storage::disk('local')->path($path);
            $directory = dirname($absolutePath);

            if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
                throw new LogicException('Unable to create the private export directory.');
            }

            $writer->openToFile($absolutePath);
            $writer->addRow(Row::fromValues($this->headings($type)));
            $rowCount = $this->writeRows($writer, $type, $filters);
            $writer->close();

            return $rowCount;
        } catch (Throwable $throwable) {
            $this->closeAfterFailure($writer);
            Storage::disk('local')->delete($path);

            throw $throwable;
        }
    }

    /** @param array<string, mixed> $filters */
    private function writeRows(Writer $writer, EmployeeReportType $type, array $filters): int
    {
        $rowCount = 0;

        $this->employeeReportService->query($type, $filters)->chunkById(
            500,
            function (Collection $records) use ($writer, &$rowCount): void {
                foreach ($records as $record) {
                    $writer->addRow(Row::fromValues($this->values($record)));
                    $rowCount++;
                }
            },
        );

        return $rowCount;
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

    private function reportType(DocumentExport|EmployeeReportExport $export): EmployeeReportType
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
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }

    private function exportId(DocumentExport|EmployeeReportExport $export): int
    {
        $key = $export->getKey();

        if (! is_int($key)) {
            throw new LogicException('Employee report exports must use integer identifiers.');
        }

        return $key;
    }

    /** @return array<string, mixed> */
    private function filters(DocumentExport|EmployeeReportExport $export): array
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
