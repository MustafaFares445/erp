<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\EmployeePermission;
use App\Enums\PlanTaskStatus;
use App\Models\PlanTask;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class EmployeesTaskTrend extends ChartWidget
{
    protected ?string $heading = 'Tasks completed';

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
    protected function getData(): array
    {
        /** @var Collection<int, Carbon> $months */
        $months = collect(range(5, 0))
            ->map(fn (int $offset): Carbon => Carbon::today()->startOfMonth()->subMonths($offset));

        /** @var Collection<int, Carbon> $completedAt */
        $completedAt = PlanTask::query()
            ->where('status', PlanTaskStatus::Completed->value)
            ->pluck('completed_at')
            ->filter()
            ->map(fn (mixed $value): Carbon => Carbon::parse($value));

        $counts = $months->map(fn (Carbon $month): int => $completedAt
            ->filter(fn (Carbon $date): bool => $date->isSameMonth($month) && $date->isSameYear($month))
            ->count());

        return [
            'datasets' => [[
                'label' => 'Tasks completed',
                'data' => $counts->values()->all(),
            ]],
            'labels' => $months->map(fn (Carbon $month): string => $month->format('M Y'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
