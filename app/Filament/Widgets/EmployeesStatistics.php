<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\EmployeePermission;
use App\Enums\PlanTaskStatus;
use App\Enums\SalesOpportunityStatus;
use App\Filament\Resources\SalesOpportunities\SalesOpportunityResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\Visits\VisitResource;
use App\Filament\Widgets\Concerns\BuildsTrendStats;
use App\Filament\Widgets\Concerns\ScopesToSelectedEmployee;
use App\Models\CustomerVisit;
use App\Models\PlanTask;
use App\Models\SalesOpportunity;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The field team's headline cards: work done in the selected window (tasks
 * completed, visits made) against the previous window, and the live queues
 * (open tasks, opportunities awaiting review).
 */
final class EmployeesStatistics extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    use BuildsTrendStats;
    use ScopesToSelectedEmployee;

    #[\Override]
    public static function canView(): bool
    {
        $user = auth()->user();
        if ($user?->can(EmployeePermission::EmployeeView->value) ?? false) {
            return true;
        }

        return (bool) ($user?->can(EmployeePermission::TaskView->value) ?? false);
    }

    #[\Override]
    protected function getStats(): array
    {
        $period = $this->dashboardPeriod();

        $completed = $this->scopeTasksToEmployee(PlanTask::query())
            ->where('status', PlanTaskStatus::Completed->value)
            ->whereBetween('completed_at', [$period->from, $period->to])
            ->pluck('completed_at');
        $previousCompleted = $this->scopeTasksToEmployee(PlanTask::query())
            ->where('status', PlanTaskStatus::Completed->value)
            ->whereBetween('completed_at', [$period->previousFrom, $period->previousTo])
            ->count();

        $visits = $this->scopeVisitsToEmployee(CustomerVisit::query())
            ->whereBetween('planned_at', [$period->from, $period->to])
            ->pluck('planned_at');
        $previousVisits = $this->scopeVisitsToEmployee(CustomerVisit::query())
            ->whereBetween('planned_at', [$period->previousFrom, $period->previousTo])
            ->count();

        $openTasks = $this->scopeTasksToEmployee(PlanTask::query())
            ->whereIn('status', [PlanTaskStatus::Pending->value, PlanTaskStatus::InProgress->value])
            ->count();
        $overdueTasks = $this->scopeTasksToEmployee(PlanTask::query())->overdue()->count();

        $awaitingReview = $this->scopeOpportunitiesToEmployee(SalesOpportunity::query())
            ->where('status', SalesOpportunityStatus::Draft->value)
            ->count();

        return [
            $this->trendStat(
                __('dashboards.employees.kpis.tasks_completed'),
                (string) $completed->count(),
                $completed->count(),
                $previousCompleted,
                $period->countSeries($completed),
                Heroicon::OutlinedCheckCircle,
                TaskResource::getUrl(),
            ),
            $this->trendStat(
                __('dashboards.employees.kpis.visits'),
                (string) $visits->count(),
                $visits->count(),
                $previousVisits,
                $period->countSeries($visits),
                Heroicon::OutlinedMapPin,
                VisitResource::getUrl(),
            ),
            Stat::make(__('dashboards.employees.kpis.open_tasks'), (string) $openTasks)
                ->description(__('dashboards.employees.kpis.overdue', ['count' => $overdueTasks]))
                ->color($overdueTasks > 0 ? 'danger' : 'success')
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                ->url(TaskResource::getUrl()),
            Stat::make(__('dashboards.employees.kpis.opportunities_awaiting_review'), (string) $awaitingReview)
                ->color($awaitingReview > 0 ? 'warning' : 'success')
                ->icon(Heroicon::OutlinedLightBulb)
                ->url(SalesOpportunityResource::getUrl()),
        ];
    }
}
