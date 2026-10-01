<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\MaintenanceBillingType;
use App\Enums\SupportPermission;
use App\Enums\WarrantyClaimDecision;
use App\Enums\WarrantyCoverageSource;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Models\MaintenanceRecord;
use App\Models\WarrantyRecoveryClaim;
use App\Services\Support\MaintenanceCostService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class SupportWarrantyStatistics extends StatsOverviewWidget
{
    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(SupportPermission::MaintenanceCostView->value) ?? false;
    }

    #[\Override]
    protected function getStats(): array
    {
        $period = [now()->startOfMonth(), now()->endOfMonth()];
        $costService = app(MaintenanceCostService::class);

        $warrantyJobs = MaintenanceRecord::query()
            ->where('coverage_source', WarrantyCoverageSource::SellerWarranty->value)
            ->whereBetween('coverage_decided_at', $period)
            ->count();

        $warrantyCost = MaintenanceRecord::query()
            ->where('coverage_source', WarrantyCoverageSource::SellerWarranty->value)
            ->whereBetween('coverage_decided_at', $period)
            ->get()
            ->sum(static fn (MaintenanceRecord $record): int => $costService->jobCost($record)['total_cost_minor']);

        $goodwillCost = MaintenanceRecord::query()
            ->where('coverage_decision', WarrantyClaimDecision::Goodwill->value)
            ->whereBetween('coverage_decided_at', $period)
            ->get()
            ->sum(static fn (MaintenanceRecord $record): int => $costService->jobCost($record)['total_cost_minor']);

        $customerPaidRevenue = MaintenanceRecord::query()
            ->whereIn('billing_type', [
                MaintenanceBillingType::Invoiced->value,
                MaintenanceBillingType::TicketSettled->value,
            ])
            ->whereBetween('billed_at', $period)
            ->get()
            ->sum(static fn (MaintenanceRecord $record): int => $costService->marginFor($record)['revenue_minor']);

        $recoveryReceived = (int) WarrantyRecoveryClaim::query()
            ->whereBetween('updated_at', $period)
            ->sum('received_amount_minor');

        $recoveryOutstanding = WarrantyRecoveryClaim::query()
            ->get()
            ->sum(static fn (WarrantyRecoveryClaim $claim): int => $claim->outstandingMinor());

        return [
            Stat::make('Warranty jobs this month', $warrantyJobs)
                ->description('Repairs using the seller-warranty entitlement')
                ->url(MaintenanceRequestResource::getUrl('index')),
            Stat::make('Warranty service cost', self::money($warrantyCost))
                ->description('Internal cost carried by seller warranty')
                ->url(MaintenanceRequestResource::getUrl('index')),
            Stat::make('Goodwill cost', self::money($goodwillCost))
                ->description('Commercial courtesy kept separate from warranty')
                ->url(MaintenanceRequestResource::getUrl('index')),
            Stat::make('Customer-paid service', self::money($customerPaidRevenue))
                ->description('Service revenue commercially settled this month')
                ->url(MaintenanceRequestResource::getUrl('index')),
            Stat::make('Third-party recovery received', self::money($recoveryReceived))
                ->description('Manufacturer / supplier reimbursement recorded this month')
                ->url(MaintenanceRequestResource::getUrl('index')),
            Stat::make('Recovery outstanding', self::money($recoveryOutstanding))
                ->description('Approved or claimed third-party amount still not received')
                ->url(MaintenanceRequestResource::getUrl('index')),
        ];
    }

    private static function money(int $minor): string
    {
        return number_format($minor / 100, 2);
    }
}
