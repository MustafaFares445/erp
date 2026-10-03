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
use App\Support\MoneyFormatter;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Customers ranked by confirmed order value in the selected period.
 */
final class TopCustomersWidget extends TableWidget
{
    use BuildsDashboardTables;
    use InteractsWithDashboardFilters;

    private const int LIMIT = 50;

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(SalesPermission::OrderView->value) ?? false;
    }

    #[\Override]
    public function table(Table $table): Table
    {
        $currency = app(CurrencyCatalogService::class)->defaultCode();

        return $this->dashboardTable($table)
            ->heading(__('dashboards.sales.tables.top_customers'))
            ->records(function (int $page, int $recordsPerPage): LengthAwarePaginator {
                $filters = SalesDashboardFilters::fromPageFilters($this->pageFilters ?? []);
                $customers = app(SalesDashboardMetricsService::class)->topCustomers($filters, self::LIMIT);

                return self::paginateRows(
                    collect($customers)->keyBy('customer_id')->all(),
                    $page,
                    $recordsPerPage,
                );
            })
            ->columns([
                TextColumn::make('label')
                    ->label(__('dashboards.sales.columns.customer'))
                    ->weight('medium'),
                TextColumn::make('orders_count')
                    ->label(__('dashboards.sales.columns.orders'))
                    ->numeric(),
                TextColumn::make('value')
                    ->label(__('dashboards.sales.columns.sales_value'))
                    ->money($currency)
                    ->description(fn (array $record): string => __('dashboards.sales.columns.average', [
                        'value' => MoneyFormatter::formatAmount(DashboardPeriod::toFloat($record['average_value']), $currency),
                    ])),
            ]);
    }
}
