<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\SalesPermission;
use App\Filament\Widgets\Sales\QuotationPerformanceWidget;
use App\Filament\Widgets\Sales\RecentSalesActivityWidget;
use App\Filament\Widgets\Sales\RequiresAttentionWidget;
use App\Filament\Widgets\Sales\SalesFunnelWidget;
use App\Filament\Widgets\Sales\SalesKpiCards;
use App\Filament\Widgets\Sales\SalesPerformanceChart;
use App\Filament\Widgets\Sales\SalespersonPerformanceWidget;
use App\Filament\Widgets\Sales\TopCustomersWidget;
use App\Filament\Widgets\Sales\TopProductsChart;
use App\Models\CustomerProfile;
use App\Models\EmployeeProfile;
use App\Services\Sales\SalesDashboardMetricsService;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;

/**
 * Sales's module landing page: confirmed-order-value KPIs, a sales trend
 * line, the quotation→order→delivery→invoice funnel, an operational
 * "requires attention" backlog, and product/customer/salesperson
 * breakdowns — all scoped by the period/salesperson/customer filter bar.
 * See {@see SalesDashboardMetricsService} for every metric's exact
 * definition.
 */
final class SalesDashboard extends ModuleDashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    #[\Override]
    public static function canAccess(): bool
    {
        $user = auth()->user();
        if ($user?->can(SalesPermission::QuotationView->value) ?? false) {
            return true;
        }
        if ($user?->can(SalesPermission::OrderView->value) ?? false) {
            return true;
        }

        return (bool) ($user?->can(SalesPermission::InvoiceView->value) ?? false);
    }

    #[\Override]
    public function getTitle(): string
    {
        return __('admin.resources.sales_dashboard');
    }

    /** @return array<Select> */
    #[\Override]
    protected function moduleFilters(): array
    {
        return [
            Select::make('employeeId')
                ->label(__('dashboards.sales.filters.salesperson'))
                ->searchable()
                ->native(false)
                ->options(fn (): array => EmployeeProfile::query()
                    ->with('user:id,name')
                    ->get()
                    ->mapWithKeys(static fn (EmployeeProfile $employee): array => [$employee->id => (string) $employee->user?->name])
                    ->all()),
            Select::make('customerId')
                ->label(__('dashboards.sales.filters.customer'))
                ->searchable()
                ->native(false)
                ->options(fn (): array => CustomerProfile::query()->orderBy('company_name')->pluck('company_name', 'id')->all()),
        ];
    }

    #[\Override]
    protected function getDashboardWidgets(): array
    {
        return [
            SalesKpiCards::class,
            [SalesPerformanceChart::class, TopProductsChart::class],
            [TopCustomersWidget::class, SalespersonPerformanceWidget::class],
            [SalesFunnelWidget::class, QuotationPerformanceWidget::class],
            [RequiresAttentionWidget::class, RecentSalesActivityWidget::class],
        ];
    }
}
