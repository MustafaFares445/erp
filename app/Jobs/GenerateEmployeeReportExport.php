<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EmployeeReportExport;
use App\Services\Employees\EmployeeReportExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class GenerateEmployeeReportExport implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $employeeReportExportId) {}

    public function handle(EmployeeReportExportService $employeeReportExportService): void
    {
        $export = EmployeeReportExport::query()->findOrFail($this->employeeReportExportId);
        $employeeReportExportService->generate($export);
    }
}
