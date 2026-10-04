<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\CrmPermission;
use App\Enums\LeadStatus;
use App\Filament\Support\IerpColors;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Models\Lead;
use App\Support\Dashboard\DashboardPeriod;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Where the leads captured in the selected window (and lead source) stand
 * today, one doughnut slice per lifecycle status.
 */
final class CrmLeadFunnel extends ChartWidget
{
    protected static bool $isLazy = false;

    use InteractsWithDashboardFilters;

    protected ?string $maxHeight = '300px';

    private const array STATUS_COLORS = [
        'gray' => IerpColors::CHART_NEUTRAL,
        'info' => IerpColors::CHART_PRIMARY,
        'warning' => IerpColors::CHART_WARNING,
        'success' => IerpColors::CHART_SUCCESS,
        'danger' => IerpColors::CHART_DANGER,
    ];

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(CrmPermission::LeadView->value) ?? false;
    }

    #[\Override]
    public function getHeading(): string
    {
        return __('dashboards.crm.charts.leads_by_status');
    }

    #[\Override]
    protected function getData(): array
    {
        $period = $this->dashboardPeriod();

        $counts = Lead::query()
            ->whereBetween('created_at', [$period->from, $period->to])
            ->when($this->dashboardStringFilter('leadSource'), static fn (Builder $query, string $source): Builder => $query->where('source', $source))
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $statuses = LeadStatus::cases();

        return [
            'datasets' => [[
                'label' => __('dashboards.crm.charts.leads'),
                'data' => array_map(static fn (LeadStatus $status): int => DashboardPeriod::toInt($counts->get($status->value)), $statuses),
                'backgroundColor' => array_map(static fn (LeadStatus $status): string => self::STATUS_COLORS[$status->color()], $statuses),
            ]],
            'labels' => array_map(static fn (LeadStatus $status): string => $status->label(), $statuses),
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
