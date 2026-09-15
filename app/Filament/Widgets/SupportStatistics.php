<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\SupportPermission;
use App\Enums\TicketStatus;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Filament\Resources\MaintenanceSchedules\MaintenanceScheduleResource;
use App\Filament\Resources\ServiceRecords\ServiceRecordResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceScheduleOccurrence;
use App\Models\MaintenanceTask;
use App\Models\Ticket;
use App\Services\Support\MaintenanceCostService;
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

        $pendingPayment = Ticket::query()->where('status', TicketStatus::PendingPayment->value)->count();
        $slaBreaches = Ticket::query()
            ->where(function (Builder $query): void {
                $query->where(fn (Builder $query): Builder => $query->responseBreached())
                    ->orWhere(fn (Builder $query): Builder => $query->resolutionBreached());
            })
            ->count();
        $pendingMaintenanceRequests = MaintenanceRecord::query()->where('status', MaintenanceStatus::Open->value)->count();
        $serviceRecordsThisMonth = MaintenanceTask::query()
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();
        $warrantyCostThisPeriod = $this->warrantyCostThisPeriod();
        $maintenanceDueSoon = MaintenanceScheduleOccurrence::query()
            ->where('status', OccurrenceStatus::Pending->value)
            ->whereBetween('due_on', [now()->startOfDay(), now()->addDays(14)->endOfDay()])
            ->count();
        $maintenanceMissed = MaintenanceScheduleOccurrence::query()->where('status', OccurrenceStatus::Missed->value)->count();

        return [
            Stat::make('Open tickets', $openTickets)
                ->url(TicketResource::getUrl('index', ['activeTab' => 'open'])),
            Stat::make('Pending payment', $pendingPayment)
                ->color($pendingPayment > 0 ? 'warning' : 'success')
                ->url(TicketResource::getUrl('index', ['activeTab' => 'pending_payment'])),
            Stat::make('SLA breaches', $slaBreaches)
                ->color($slaBreaches > 0 ? 'danger' : 'success')
                ->url(TicketResource::getUrl('index', ['activeTab' => 'sla_breached'])),
            Stat::make('Pending maintenance requests', $pendingMaintenanceRequests)
                ->url(MaintenanceRequestResource::getUrl('index', ['activeTab' => 'open'])),
            Stat::make('Service records this month', $serviceRecordsThisMonth)
                ->url(ServiceRecordResource::getUrl('index', ['activeTab' => 'this_month'])),
            Stat::make('Warranty cost this period', $this->formatMoney($warrantyCostThisPeriod))
                ->url(MaintenanceRequestResource::getUrl('index', ['activeTab' => 'warranty_covered'])),
            Stat::make('Maintenance due soon', $maintenanceDueSoon)
                ->url(MaintenanceScheduleResource::getUrl('index', ['activeTab' => 'due_soon'])),
            Stat::make('Maintenance missed', $maintenanceMissed)
                ->color('danger')
                ->url(MaintenanceScheduleResource::getUrl('index', ['activeTab' => 'overdue'])),
        ];
    }

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
