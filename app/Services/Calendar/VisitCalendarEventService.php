<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Filament\Resources\MonthlyPlans\MonthlyPlanResource;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\CustomerVisit;
use App\Models\PlanTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class VisitCalendarEventService
{
    /** @return Collection<int|string, Collection<int, array{date:string,time:non-falsy-string|null,type:string,title:string,subtitle:string|null,status:string,url:string}>> */
    public function between(Carbon $from, Carbon $until): Collection
    {
        $visits = CustomerVisit::query()
            ->with(['customer:id,company_name', 'employee.user:id,name'])
            ->whereBetween('planned_at', [$from->copy()->startOfDay(), $until->copy()->endOfDay()])
            ->get()
            ->map(function (CustomerVisit $visit): ?array {
                $plannedAt = $visit->planned_at;
                if (! $plannedAt instanceof Carbon) {
                    return null;
                }

                $customerName = data_get($visit, 'customer.company_name');
                $employeeName = data_get($visit, 'employee.user.name');

                return [
                    'date' => $plannedAt->toDateString(),
                    'time' => $plannedAt->format('H:i'),
                    'type' => 'visit',
                    'title' => is_string($customerName) ? $customerName : (string) __('Customer visit'),
                    'subtitle' => is_string($employeeName) ? $employeeName : null,
                    'status' => $visit->status->value,
                    'url' => VisitResource::getUrl('view', ['record' => $visit]),
                ];
            })
            ->filter()
            ->values();

        $tasks = PlanTask::query()
            ->with(['customer:id,company_name', 'salesPlan.employee.user:id,name'])
            ->whereBetween('due_at', [$from->toDateString(), $until->toDateString()])
            ->get()
            ->map(function (PlanTask $task): array {
                $customerName = data_get($task, 'customer.company_name');
                $employeeName = data_get($task, 'salesPlan.employee.user.name');

                return [
                    'date' => $task->due_at->toDateString(),
                    'time' => null,
                    'type' => 'task',
                    'title' => (string) $task->title,
                    'subtitle' => is_string($customerName) ? $customerName : (is_string($employeeName) ? $employeeName : null),
                    'status' => $task->status->value,
                    'url' => MonthlyPlanResource::getUrl('view', ['record' => $task->sales_plan_id]),
                ];
            });

        return $visits->concat($tasks)->sortBy(['date', 'time'])->groupBy('date');
    }
}
