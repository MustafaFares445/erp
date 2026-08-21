<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EmployeeSalaryCalculation;
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

    public function handle(): void
    {
        $calculation = EmployeeSalaryCalculation::query()->find($this->salaryCalculationId);

        if (! $calculation instanceof EmployeeSalaryCalculation) {
            return;
        }

        activity()
            ->performedOn($calculation)
            ->withChanges(['attributes' => ['notified_at' => now()->toISOString()]])
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log('salary.recalculation_notified');
    }
}
