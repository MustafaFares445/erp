<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\MaintenanceStatus;
use App\Enums\SupportPermission;
use App\Enums\TicketStatus;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\MaintenanceRecord;
use App\Models\Ticket;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

final class SupportStatistics extends StatsOverviewWidget
{
    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(SupportPermission::TicketView->value) ?? false;
    }

    #[\Override]
    protected function getStats(): array
    {
        $openTickets = Ticket::query()
            ->whereNotIn('status', [
                TicketStatus::Resolved->value,
                TicketStatus::Closed->value,
                TicketStatus::Cancelled->value,
            ])
            ->count();

        $waitingDiagnosis = MaintenanceRecord::query()
            ->whereNotIn('status', [MaintenanceStatus::Closed->value, MaintenanceStatus::Cancelled->value])
            ->whereNull('diagnosed_at')
            ->count();

        $slaAtRisk = Ticket::query()
            ->whereNotIn('status', [TicketStatus::Resolved->value, TicketStatus::Closed->value, TicketStatus::Cancelled->value])
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

        $waitingCustomer = Ticket::query()
            ->where('status', TicketStatus::WaitingCustomer->value)
            ->count();
        $awaitingCoverage = MaintenanceRecord::query()
            ->where('status', MaintenanceStatus::Diagnosing->value)
            ->count();

        $awaitingApproval = MaintenanceRecord::query()
            ->where('status', MaintenanceStatus::AwaitingApproval->value)
            ->count();

        return [
            Stat::make('Open tickets', $openTickets)
                ->description('All active customer support work')
                ->url(TicketResource::getUrl('index')),
            Stat::make('Waiting diagnosis', $waitingDiagnosis)
                ->description('Maintenance jobs without technical diagnosis')
                ->color($waitingDiagnosis > 0 ? 'warning' : 'success')
                ->url(MaintenanceRequestResource::getUrl('index')),
            Stat::make('SLA at risk', $slaAtRisk)
                ->description('Breached or due within the next hour')
                ->color($slaAtRisk > 0 ? 'danger' : 'success')
                ->url(TicketResource::getUrl('index')),
            Stat::make('Waiting customer', $waitingCustomer)
                ->description('Support clock paused for customer response')
                ->color($waitingCustomer > 0 ? 'warning' : 'success')
                ->url(TicketResource::getUrl('index')),
            Stat::make('Coverage decision needed', $awaitingCoverage)
                ->description('Diagnosis recorded; decide who pays')
                ->color($awaitingCoverage > 0 ? 'warning' : 'success')
                ->url(MaintenanceRequestResource::getUrl('index')),
            Stat::make('Waiting approval', $awaitingApproval)
                ->description('Customer quotation / approval required')
                ->color($awaitingApproval > 0 ? 'warning' : 'success')
                ->url(MaintenanceRequestResource::getUrl('index')),
        ];
    }
}
