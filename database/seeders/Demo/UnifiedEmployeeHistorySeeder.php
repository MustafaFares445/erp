<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\PlanTaskStatus;
use App\Enums\SalesPlanStatus;
use App\Enums\VisitOutcome;
use App\Enums\VisitStatus;
use App\Models\CustomerProfile;
use App\Models\CustomerVisit;
use App\Models\EmployeeProfile;
use App\Models\PlanTask;
use App\Models\SalesPlan;
use App\Services\Employees\PlanTaskService;
use App\Services\Employees\SalesPlanService;
use App\Services\Employees\VisitLifecycleService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use LogicException;

/**
 * An annual, repeatable field sales roster for the ONE dev database:
 * 72 monthly plans, 504 tasks, and 360 visits (5 of 7 tasks per plan).
 *
 * Creates history outside the canonical September/October curated story and
 * uses task, sales-plan and visit lifecycle services for status transitions.
 */
final class UnifiedEmployeeHistorySeeder extends DemoSeeder
{
    private const array Activities = [
        ['Consumables stock check and replenishment discussion', 'Reorder levels and lead times confirmed'],
        ['Dental resin trial for the laboratory team', 'Lab requested a quote for the next replenishment'],
        ['Implant component compatibility review', 'Correct implant sizing and scan body confirmed'],
        ['New product portfolio introduction', 'Clinician requested sample product specifications'],
        ['Payment terms and account review', 'Accounts payable team confirmed invoice schedule'],
        ['Sterilization equipment training', 'Staff completed the sterilization workflow training'],
        ['Quarterly equipment maintenance review', 'Maintenance visit scheduled with the technical team'],
    ];

    protected function seed(DemoContext $context): void
    {
        $anchor = Carbon::parse(config()->string('demo.unified.anchor', now()->toDateString()))->startOfMonth();
        $employees = EmployeeProfile::query()->whereIn('employee_code', [
            'DEMO-EMP-001', 'DEMO-EMP-002', 'DEMO-EMP-003',
            'DEMO-EMP-004', 'DEMO-EMP-005', 'DEMO-EMP-006',
        ])->with('user')->orderBy('employee_code')->get();
        $customers = CustomerProfile::query()->where('customer_code', 'like', 'DEMO-CUST-%')
            ->where('is_active', true)->orderBy('customer_code')->get();

        if ($employees->count() !== 6 || $customers->count() < 100) {
            throw new LogicException('Unified employee history requires the full demo roster and customers.');
        }

        $planService = app(SalesPlanService::class);
        $tasks = app(PlanTaskService::class);
        $visits = app(VisitLifecycleService::class);

        foreach (range(2, 13) as $monthsBack) {
            $month = $anchor->copy()->subMonths($monthsBack);
            $monthKey = $month->format('Y-m');
            foreach ($employees as $e => $employee) {
                $manager = $context->as('employee_manager');
                $context->at($month->copy()->startOfMonth()->setTime(8, 30)->toDateTimeString());
                $name = sprintf('[DEMO-HISTORY] %s territory plan (%s)', $employee->employee_code, $monthKey);
                $plan = SalesPlan::query()->where('name', $name)
                    ->where('employee_id', $employee->getKey())->first();

                if (! $plan instanceof SalesPlan) {
                    $plan = $planService->create([
                        'employee_id' => $employee->getKey(),
                        'name' => $name,
                        'month' => $month->toDateString(),
                        'task_weight' => 35,
                        'visit_weight' => 35,
                        'schedule_weight' => 20,
                        'work_time_weight' => 10,
                        'opportunity_weight' => 0,
                        'required_visit_minutes' => 35,
                    ]);
                }

                for ($j = 0; $j < 7; $j++) {
                    $customer = $customers[(($e + 1) * 19 + $monthsBack * 13 + $j * 7) % $customers->count()]
                        ?? throw new LogicException('Missing customer for field activity.');
                    [$activity, $result] = self::Activities[$j];
                    $day = $month->copy()->startOfMonth()->addDays(2 + $j * 3);
                    $starts = $day->copy()->setTime(9, 0);
                    $due = $day->copy()->setTime(18, 0);
                    $title = sprintf('[DEMO-HISTORY] %s - %s', $activity, $monthKey);

                    $task = PlanTask::query()->where('sales_plan_id', $plan->getKey())
                        ->where('title', $title)->first();
                    if (! $task instanceof PlanTask) {
                        $context->at($starts->copy()->subHours(2)->toDateTimeString());
                        $context->as('employee_manager');
                        $task = $tasks->create($plan, [
                            'title' => $title,
                            'description' => sprintf('Synthetic %s region field activity. %s.', $customer->city ?? 'UAE', $result),
                            'customer_id' => $customer->getKey(),
                            'starts_at' => $starts, 'due_at' => $due,
                        ]);
                    }

                    // Tasks may already exist from a partially interrupted run.
                    if ($task->status === PlanTaskStatus::Pending && $j % 7 !== 6) {
                        $context->at($starts->toDateTimeString());
                        $context->as('employee_manager');
                        $task = $tasks->transition($task, PlanTaskStatus::InProgress, 'Field work started.');
                    }
                    if ($task->status === PlanTaskStatus::InProgress && $j % 7 < 4) {
                        $context->at($starts->copy()->addHours(3)->toDateTimeString());
                        $task = $tasks->transition($task, PlanTaskStatus::Completed, 'Field activity completed.');
                    }
                    if ($task->status === PlanTaskStatus::Pending && $j === 6) {
                        $context->at($starts->copy()->addHours(1)->toDateTimeString());
                        $task = $tasks->transition($task, PlanTaskStatus::Cancelled, 'Customer changed priorities.');
                    }

                    if ($j >= 5) {
                        continue; // Two operational tasks per plan have no site visit.
                    }

                    $visit = CustomerVisit::query()->where('plan_task_id', $task->getKey())
                        ->where('customer_id', $customer->getKey())->first();
                    if ($visit instanceof CustomerVisit) {
                        continue;
                    }

                    $context->at($starts->copy()->subMinutes(45)->toDateTimeString());
                    if (! $employee->user) {
                        throw new LogicException('Field rep needs a demo app identity.');
                    }
                    Auth::setUser($employee->user);
                    $visit = CustomerVisit::query()->create([
                        'employee_id' => $employee->getKey(),
                        'plan_task_id' => $task->getKey(),
                        'customer_id' => $customer->getKey(),
                        'planned_at' => $starts, 'scheduled_start_at' => $starts,
                        'scheduled_end_at' => $starts->copy()->addHour(),
                        'status' => VisitStatus::Scheduled,
                    ]);

                    // 10% customer unavailable/cancelled, others completed
                    // with plausible location evidence and service lifecycle.
                    if ($j === 4 && $monthsBack % 3 === 0) {
                        $visits->cancel($visit, 'Customer cancelled the prearranged demonstration.');

                        continue;
                    }

                    $context->at($starts->copy()->subMinutes(15)->toDateTimeString());
                    $visit = $visits->markEnRoute($visit);
                    if ($j === 3 && $monthsBack % 3 === 1) {
                        $context->at($starts->copy()->addMinutes(5)->toDateTimeString());
                        $visits->unableToComplete($visit, 'Clinic closed unexpectedly; reschedule with reception.');

                        continue;
                    }

                    $context->at($starts->copy()->addMinutes($j % 3)->toDateTimeString());
                    $visit = $visits->checkIn($visit, (float) $customer->latitude, (float) $customer->longitude, 12.0);
                    $context->at($starts->copy()->addMinutes(40 + $j * 5)->toDateTimeString());
                    $outcome = $j === 2 ? VisitOutcome::NotInterested : VisitOutcome::Successful;
                    $visits->complete($visit, $outcome, $result.'. Detailed service notes are synthetic.');
                }

                if ($plan->status === SalesPlanStatus::Draft) {
                    $context->as('employee_manager');
                    $context->at($month->copy()->startOfMonth()->addHours(2)->toDateTimeString());
                    $plan = $planService->transition($plan->refresh(), SalesPlanStatus::Published);
                    $planService->transition($plan->refresh(), SalesPlanStatus::InProgress);
                }
            }
        }

        $this->note('Added 72 annual sales plans, 504 real-service tasks and 360 traced field visits.');
    }
}
