<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\CrmPermission;
use App\Models\CustomerProfile;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class CrmCustomerGrowthTrend extends ChartWidget
{
    protected ?string $heading = 'Customer growth';

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(CrmPermission::CustomerView->value) ?? false;
    }

    #[\Override]
    protected function getData(): array
    {
        /** @var Collection<int, Carbon> $months */
        $months = collect(range(5, 0))
            ->map(fn (int $offset): Carbon => Carbon::today()->startOfMonth()->subMonths($offset));

        /** @var Collection<int, Carbon> $createdAt */
        $createdAt = CustomerProfile::query()
            ->pluck('created_at')
            ->filter()
            ->map(fn (mixed $value): Carbon => Carbon::parse($value));

        $counts = $months->map(fn (Carbon $month): int => $createdAt
            ->filter(fn (Carbon $date): bool => $date->isSameMonth($month) && $date->isSameYear($month))
            ->count());

        return [
            'datasets' => [[
                'label' => 'New customers',
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
