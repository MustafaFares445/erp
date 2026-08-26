<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\MaintenanceStatus;
use App\Enums\SupportPermission;
use App\Enums\TicketStatus;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\Ticket;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

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

        $slaBreaches = Ticket::query()->resolutionBreached()->count();

        $pendingMaintenanceRequests = MaintenanceRecord::query()
            ->where('status', MaintenanceStatus::Open->value)
            ->count();

        $serviceRecordsThisMonth = MaintenanceTask::query()
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();

        return [
            Stat::make('Open tickets', $openTickets),
            Stat::make('SLA breaches', $slaBreaches),
            Stat::make('Pending maintenance requests', $pendingMaintenanceRequests),
            Stat::make('Service records this month', $serviceRecordsThisMonth),
        ];
    }
}
