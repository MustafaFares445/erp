<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EmployeeSalaryCalculation;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Notifies admins that a plan change produced a new salary calculation
 * needing confirmation (FR-065). Queued so a delivery failure can never
 * reverse, block, or invalidate the already-written calculation (FR-069) —
 * it simply retries through the standard queue mechanism.
 */
final class NotifyAdminOfSalaryRecalculation implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $salaryCalculationId) {}

    public function handle(AuditLogger $auditLogger): void
    {
        $calculation = EmployeeSalaryCalculation::query()->find($this->salaryCalculationId);

        if (! $calculation instanceof EmployeeSalaryCalculation) {
            return;
        }

        $auditLogger->log(
            action: 'salary.recalculation_notified',
            entity: $calculation,
            newValues: ['notified_at' => now()->toISOString()],
        );
    }
}
