<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\SupportPermission;
use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Widgets\Concerns\BuildsTrendStats;
use App\Filament\Widgets\Concerns\ScopesSupportTickets;
use App\Models\Ticket;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Support's four headline cards: tickets opened and resolved in the
 * selected window against the previous one, the live open queue, and the
 * tickets whose SLA is breached or due within the hour. Maintenance work
 * queues live in the maintenance attention table.
 */
final class SupportStatistics extends StatsOverviewWidget
{
    use BuildsTrendStats;
    use ScopesSupportTickets;

    private const array CLOSED_STATUSES = [
        TicketStatus::Resolved,
        TicketStatus::Closed,
        TicketStatus::Cancelled,
    ];

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(SupportPermission::TicketView->value) ?? false;
    }

    #[\Override]
    protected function getStats(): array
    {
        $period = $this->dashboardPeriod();

        $opened = $this->tickets()->whereBetween('created_at', [$period->from, $period->to])->pluck('created_at');
        $previousOpened = $this->tickets()->whereBetween('created_at', [$period->previousFrom, $period->previousTo])->count();
        $resolved = $this->tickets()->whereBetween('resolved_at', [$period->from, $period->to])->pluck('resolved_at');
        $previousResolved = $this->tickets()->whereBetween('resolved_at', [$period->previousFrom, $period->previousTo])->count();

        $open = $this->openTickets()->count();
        $waitingCustomer = $this->openTickets()->where('status', TicketStatus::WaitingCustomer->value)->count();

        $slaAtRisk = $this->openTickets()
            ->where(function (Builder $query): void {
                $query->where(function (Builder $response): void {
                    $response->whereNull('first_response_at')
                        ->whereNotNull('response_due_at')
                        ->whereBetween('response_due_at', [now(), now()->addHour()]);
                })->orWhere(function (Builder $resolution): void {
                    $resolution->whereNull('resolved_at')
                        ->whereNotNull('resolution_due_at')
                        ->whereBetween('resolution_due_at', [now(), now()->addHour()]);
                })->orWhere('response_breached', true)
                    ->orWhere('resolution_breached', true);
            })
            ->count();

        return [
            $this->trendStat(
                __('dashboards.support.kpis.opened'),
                (string) $opened->count(),
                $opened->count(),
                $previousOpened,
                $period->countSeries($opened),
                Heroicon::OutlinedInboxArrowDown,
                TicketResource::getUrl('index'),
                higherIsBetter: false,
            ),
            $this->trendStat(
                __('dashboards.support.kpis.resolved'),
                (string) $resolved->count(),
                $resolved->count(),
                $previousResolved,
                $period->countSeries($resolved),
                Heroicon::OutlinedCheckBadge,
                TicketResource::getUrl('index'),
            ),
            Stat::make(__('dashboards.support.kpis.open'), (string) $open)
                ->description(__('dashboards.support.kpis.waiting_customer', ['count' => $waitingCustomer]))
                ->icon(Heroicon::OutlinedLifebuoy)
                ->url(TicketResource::getUrl('index')),
            Stat::make(__('dashboards.support.kpis.sla_at_risk'), (string) $slaAtRisk)
                ->description(__('dashboards.support.kpis.sla_at_risk_detail'))
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color($slaAtRisk > 0 ? 'danger' : 'success')
                ->url(TicketResource::getUrl('index')),
        ];
    }

    /** @return Builder<Ticket> */
    private function tickets(): Builder
    {
        return $this->scopeTickets(Ticket::query());
    }

    /** @return Builder<Ticket> */
    private function openTickets(): Builder
    {
        return $this->tickets()->whereNotIn('status', array_map(static fn (TicketStatus $status): string => $status->value, self::CLOSED_STATUSES));
    }
}
