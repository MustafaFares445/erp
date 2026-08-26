<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\EmployeePermission;
use App\Enums\PlanTaskStatus;
use App\Enums\SalesOpportunityStatus;
use App\Models\CustomerVisit;
use App\Models\EmployeeProfile;
use App\Models\PlanTask;
use App\Models\SalesOpportunity;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

final class EmployeesStatistics extends StatsOverviewWidget
{
    #[\Override]
    public static function canView(): bool
    {
        $user = auth()->user();

        return ($user?->can(EmployeePermission::EmployeeView->value) ?? false)
            || ($user?->can(EmployeePermission::TaskView->value) ?? false);
    }

    #[\Override]
    protected function getStats(): array
    {
        return [
            Stat::make('Active employees', EmployeeProfile::query()->where('is_active', true)->count()),
            Stat::make('Open tasks', PlanTask::query()->whereIn('status', [
                PlanTaskStatus::Pending->value,
                PlanTaskStatus::InProgress->value,
            ])->count()),
            Stat::make('Visits today', CustomerVisit::query()->whereDate('planned_at', Carbon::today())->count()),
            Stat::make('Pending opportunities', SalesOpportunity::query()->where('status', SalesOpportunityStatus::Draft->value)->count()),
        ];
    }
}
