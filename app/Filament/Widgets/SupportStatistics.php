<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\SupportPermission;
use App\Enums\TicketStatus;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceScheduleOccurrence;
use App\Models\MaintenanceTask;
use App\Models\Ticket;
use App\Services\Support\MaintenanceCostService;
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

        $warrantyCostThisPeriod = $this->warrantyCostThisPeriod();

        $maintenanceDueSoon = MaintenanceScheduleOccurrence::query()
            ->where('status', OccurrenceStatus::Pending->value)
            ->whereBetween('due_on', [now()->startOfDay(), now()->addDays(14)->endOfDay()])
            ->count();

        $maintenanceMissed = MaintenanceScheduleOccurrence::query()
            ->where('status', OccurrenceStatus::Missed->value)
            ->count();

        return [
            Stat::make('Open tickets', $openTickets),
            Stat::make('SLA breaches', $slaBreaches),
            Stat::make('Pending maintenance requests', $pendingMaintenanceRequests),
            Stat::make('Service records this month', $serviceRecordsThisMonth),
            Stat::make('Warranty cost this period', $this->formatMoney($warrantyCostThisPeriod)),
            Stat::make('Maintenance due soon', $maintenanceDueSoon),
            Stat::make('Maintenance missed', $maintenanceMissed)->color('danger'),
        ];
    }

    /**
     * Total real cost of warranty-covered jobs billed this month (WP-2.9,
     * GAP-MW-09) — MT-05's "free-of-charge service made visible as a cost
     * centre", surfaced where it is most visible.
     */
    private function warrantyCostThisPeriod(): int
    {
        $costService = app(MaintenanceCostService::class);

        return MaintenanceRecord::query()
            ->where('billing_type', MaintenanceBillingType::WarrantyCovered->value)
            ->whereBetween('billed_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->get()
            ->sum(fn (MaintenanceRecord $record): int => $costService->jobCost($record)['total_cost_minor']);
    }

    private function formatMoney(int $minor): string
    {
        return number_format($minor / 100, 2);
    }
}
