<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Sales;

use App\Enums\SalesPermission;
use App\Filament\Widgets\Concerns\BuildsDashboardTables;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Services\Sales\SalesDashboardFilters;
use App\Services\Sales\SalesDashboardMetricsService;
use App\Services\Settings\CurrencyCatalogService;
use App\Support\Dashboard\DashboardPeriod;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Hidden entirely when no quotation in the system carries a salesperson —
 * ownership is never inferred from an unrelated column (see
 * {@see SalesDashboardMetricsService} for why `Order.responsible_id`
 * doesn't count). Its row partner then spans the full width.
 */
final class SalespersonPerformanceWidget extends TableWidget
{
    protected static bool $isLazy = false;

    use BuildsDashboardTables;
    use InteractsWithDashboardFilters;

    #[\Override]
    public static function canView(): bool
    {
        if (! (auth()->user()?->can(SalesPermission::QuotationView->value) ?? false)) {
            return false;
        }

        return app(SalesDashboardMetricsService::class)->hasSalespersonData();
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $this->dashboardTable($table)
            ->heading(__('dashboards.sales.tables.salesperson_performance'))
            ->records(function (int $page, int $recordsPerPage): LengthAwarePaginator {
                $filters = SalesDashboardFilters::fromPageFilters($this->pageFilters ?? []);
                $salespeople = app(SalesDashboardMetricsService::class)->salespersonPerformance($filters);

                return self::paginateRows(
                    collect($salespeople)->keyBy('employee_id')->all(),
                    $page,
                    $recordsPerPage,
                );
            })
            ->columns([
                TextColumn::make('label')
                    ->label(__('dashboards.sales.columns.salesperson'))
                    ->weight('medium')
                    ->description(fn (array $record): string => __('dashboards.sales.columns.conversion_detail', [
                        'quotations' => DashboardPeriod::toInt($record['quotations']),
                        'percent' => number_format(DashboardPeriod::toFloat($record['conversion_percent']), 1),
                    ])),
                TextColumn::make('orders')
                    ->label(__('dashboards.sales.columns.orders'))
                    ->numeric(),
                TextColumn::make('value')
                    ->label(__('dashboards.sales.columns.sales_value'))
                    ->money(app(CurrencyCatalogService::class)->defaultCode()),
            ]);
    }
}
