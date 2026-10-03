<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\MaintenanceBillingType;
use App\Enums\SupportPermission;
use App\Enums\WarrantyClaimDecision;
use App\Enums\WarrantyCoverageSource;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Models\MaintenanceRecord;
use App\Models\WarrantyRecoveryClaim;
use App\Services\Settings\CurrencyCatalogService;
use App\Services\Support\MaintenanceCostService;
use Filament\Widgets\ChartWidget;

/**
 * Service economics for the selected window as one bar chart: cost carried
 * by seller warranty and by goodwill, customer-paid service revenue, and
 * third-party recovery received — plus the recovery still outstanding
 * today. Amounts are in the default currency.
 */
final class SupportWarrantyStatistics extends ChartWidget
{
    use InteractsWithDashboardFilters;

    protected ?string $maxHeight = '300px';

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(SupportPermission::MaintenanceCostView->value) ?? false;
    }

    #[\Override]
    public function getHeading(): string
    {
        return __('dashboards.support.charts.service_economics', ['currency' => app(CurrencyCatalogService::class)->defaultCode()]);
    }

    #[\Override]
    protected function getData(): array
    {
        $period = $this->dashboardPeriod();
        $window = [$period->from, $period->to];
        $costService = app(MaintenanceCostService::class);

        $warrantyCost = MaintenanceRecord::query()
            ->where('coverage_source', WarrantyCoverageSource::SellerWarranty->value)
            ->whereBetween('coverage_decided_at', $window)
            ->get()
            ->sum(static fn (MaintenanceRecord $record): int => $costService->jobCost($record)['total_cost_minor']);

        $goodwillCost = MaintenanceRecord::query()
            ->where('coverage_decision', WarrantyClaimDecision::Goodwill->value)
            ->whereBetween('coverage_decided_at', $window)
            ->get()
            ->sum(static fn (MaintenanceRecord $record): int => $costService->jobCost($record)['total_cost_minor']);

        $customerPaidRevenue = MaintenanceRecord::query()
            ->whereIn('billing_type', [
                MaintenanceBillingType::Invoiced->value,
                MaintenanceBillingType::TicketSettled->value,
            ])
            ->whereBetween('billed_at', $window)
            ->get()
            ->sum(static fn (MaintenanceRecord $record): int => $costService->marginFor($record)['revenue_minor']);

        $recoveryReceived = (int) WarrantyRecoveryClaim::query()
            ->whereBetween('updated_at', $window)
            ->sum('received_amount_minor');

        $recoveryOutstanding = WarrantyRecoveryClaim::query()
            ->get()
            ->sum(static fn (WarrantyRecoveryClaim $claim): int => $claim->outstandingMinor());

        return [
            'datasets' => [[
                'label' => __('dashboards.support.charts.amount'),
                'data' => array_map(
                    static fn (int|float $minor): float => round($minor / 100, 2),
                    [$warrantyCost, $goodwillCost, $customerPaidRevenue, $recoveryReceived, $recoveryOutstanding],
                ),
                'backgroundColor' => ['#f59e0b', '#a855f7', '#22c55e', '#3b82f6', '#94a3b8'],
            ]],
            'labels' => [
                __('dashboards.support.economics.warranty_cost'),
                __('dashboards.support.economics.goodwill_cost'),
                __('dashboards.support.economics.customer_paid'),
                __('dashboards.support.economics.recovery_received'),
                __('dashboards.support.economics.recovery_outstanding'),
            ],
        ];
    }

    #[\Override]
    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true]],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
