<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\EmployeePermission;
use App\Enums\PlanTaskStatus;
use App\Filament\Support\IerpColors;
use App\Filament\Widgets\Concerns\ScopesToSelectedEmployee;
use App\Models\PlanTask;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Collection;

final class EmployeesTaskTrend extends ChartWidget
{
    protected static bool $isLazy = false;

    use ScopesToSelectedEmployee;

    protected ?string $maxHeight = '300px';

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
    public function getHeading(): string
    {
        return __('dashboards.employees.charts.tasks_completed');
    }

    /** Completed tasks per bucket of the selected window and of the previous one. */
    #[\Override]
    protected function getData(): array
    {
        $period = $this->dashboardPeriod();

        return [
            'datasets' => [
                [
                    'label' => __('dashboards.charts.selected_period'),
                    'data' => $period->countSeries($this->completedBetween($period->from, $period->to)),
                    'borderColor' => IerpColors::CHART_PRIMARY,
                    'backgroundColor' => 'transparent',
                ],
                [
                    'label' => __('dashboards.charts.previous_period'),
                    'data' => $period->countSeries($this->completedBetween($period->previousFrom, $period->previousTo), previous: true),
                    'borderColor' => IerpColors::CHART_NEUTRAL,
                    'backgroundColor' => 'transparent',
                    'borderDash' => [6, 4],
                ],
            ],
            'labels' => $period->labels(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    /** @return Collection<int, mixed> */
    private function completedBetween(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->scopeTasksToEmployee(PlanTask::query())
            ->where('status', PlanTaskStatus::Completed->value)
            ->whereBetween('completed_at', [$from, $to])
            ->pluck('completed_at');
    }
}
