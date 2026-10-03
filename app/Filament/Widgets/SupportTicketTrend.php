<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\SupportPermission;
use App\Filament\Widgets\Concerns\ScopesSupportTickets;
use App\Models\Ticket;
use Filament\Widgets\ChartWidget;

/**
 * Tickets opened vs resolved per bucket of the selected window, for the
 * selected assignee and priority.
 */
final class SupportTicketTrend extends ChartWidget
{
    use ScopesSupportTickets;

    protected ?string $maxHeight = '300px';

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(SupportPermission::TicketView->value) ?? false;
    }

    #[\Override]
    public function getHeading(): string
    {
        return __('dashboards.support.charts.ticket_trend');
    }

    #[\Override]
    protected function getData(): array
    {
        $period = $this->dashboardPeriod();

        return [
            'datasets' => [
                [
                    'label' => __('dashboards.support.charts.opened'),
                    'data' => $period->countSeries(
                        $this->scopeTickets(Ticket::query())->whereBetween('created_at', [$period->from, $period->to])->pluck('created_at'),
                    ),
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'transparent',
                ],
                [
                    'label' => __('dashboards.support.charts.resolved'),
                    'data' => $period->countSeries(
                        $this->scopeTickets(Ticket::query())->whereBetween('resolved_at', [$period->from, $period->to])->pluck('resolved_at'),
                    ),
                    'borderColor' => '#22c55e',
                    'backgroundColor' => 'transparent',
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
