<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\CrmPermission;
use App\Filament\Support\IerpColors;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Models\CustomerProfile;
use Filament\Widgets\ChartWidget;

final class CrmCustomerGrowthTrend extends ChartWidget
{
    protected static bool $isLazy = false;

    use InteractsWithDashboardFilters;

    protected ?string $maxHeight = '300px';

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(CrmPermission::CustomerView->value) ?? false;
    }

    #[\Override]
    public function getHeading(): string
    {
        return __('dashboards.crm.charts.customer_growth');
    }

    /** New customers per bucket of the selected window and of the previous one. */
    #[\Override]
    protected function getData(): array
    {
        $period = $this->dashboardPeriod();

        return [
            'datasets' => [
                [
                    'label' => __('dashboards.charts.selected_period'),
                    'data' => $period->countSeries(
                        CustomerProfile::query()->whereBetween('created_at', [$period->from, $period->to])->pluck('created_at'),
                    ),
                    'borderColor' => IerpColors::CHART_PRIMARY,
                    'backgroundColor' => 'transparent',
                ],
                [
                    'label' => __('dashboards.charts.previous_period'),
                    'data' => $period->countSeries(
                        CustomerProfile::query()->whereBetween('created_at', [$period->previousFrom, $period->previousTo])->pluck('created_at'),
                        previous: true,
                    ),
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
}
