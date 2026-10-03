<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\EmployeePermission;
use App\Enums\PlanTaskStatus;
use App\Filament\Widgets\Concerns\ScopesToSelectedEmployee;
use App\Models\PlanTask;
use App\Support\Dashboard\DashboardPeriod;
use Filament\Widgets\ChartWidget;

/**
 * Where the tasks due in the selected window stand today.
 */
final class EmployeesTaskStatusChart extends ChartWidget
{
    use ScopesToSelectedEmployee;

    protected ?string $maxHeight = '300px';

    private const array STATUS_COLORS = [
        'Pending' => '#9ca3af',
        'InProgress' => '#3b82f6',
        'Completed' => '#22c55e',
        'Cancelled' => '#ef4444',
    ];

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
        return __('dashboards.employees.charts.tasks_by_status');
    }

    #[\Override]
    protected function getData(): array
    {
        $period = $this->dashboardPeriod();

        $counts = $this->scopeTasksToEmployee(PlanTask::query())
            ->whereDate('due_at', '>=', $period->from->toDateString())
            ->whereDate('due_at', '<=', $period->to->toDateString())
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $statuses = PlanTaskStatus::cases();

        return [
            'datasets' => [[
                'label' => __('dashboards.employees.charts.tasks'),
                'data' => array_map(static fn (PlanTaskStatus $status): int => DashboardPeriod::toInt($counts->get($status->value)), $statuses),
                'backgroundColor' => array_map(static fn (PlanTaskStatus $status): string => self::STATUS_COLORS[$status->value], $statuses),
            ]],
            'labels' => array_map(static fn (PlanTaskStatus $status): string => $status->label(), $statuses),
        ];
    }

    #[\Override]
    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['position' => 'bottom']],
            'scales' => [
                'x' => ['display' => false],
                'y' => ['display' => false],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
