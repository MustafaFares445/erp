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
use App\Services\Sales\SalesDashboardFilters;
use App\Services\Sales\SalesDashboardMetricsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Sales's module landing page: confirmed-order-value KPIs, a sales trend
 * line, the quotation→order→delivery→invoice funnel, an operational
 * "requires attention" backlog, and product/customer/salesperson
 * breakdowns — all scoped by the global period/salesperson/customer
 * filter bar below. See {@see SalesDashboardMetricsService}
 * for every metric's exact definition.
 *
 * Extends the generic {@see Page}, not the base {@see Dashboard},
 * so the filters form and widgets are composed manually in {@see self::content()}
 * rather than relying on Dashboard's own layout.
 */
final class SalesDashboard extends Page
{
    use HasFiltersForm;

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
    public static function getNavigationLabel(): string
    {
        return __('admin.dashboard');
    }

    #[\Override]
    public function getTitle(): string
    {
        return __('admin.resources.sales_dashboard');
    }

    /** @return array<Action> */
    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('resetFilters')
                ->label('Reset filters')
                ->color('gray')
                ->action(function (): void {
                    $this->filters = null;
                    $this->getFiltersForm()->fill();
                }),
        ];
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('period')
                ->label('Period')
                ->options([
                    SalesDashboardFilters::PERIOD_TODAY => 'Today',
                    SalesDashboardFilters::PERIOD_LAST_7_DAYS => 'Last 7 days',
                    SalesDashboardFilters::PERIOD_LAST_30_DAYS => 'Last 30 days',
                    SalesDashboardFilters::PERIOD_THIS_MONTH => 'This month',
                    SalesDashboardFilters::PERIOD_LAST_MONTH => 'Last month',
                    SalesDashboardFilters::PERIOD_THIS_QUARTER => 'This quarter',
                    SalesDashboardFilters::PERIOD_THIS_YEAR => 'This year',
                    SalesDashboardFilters::PERIOD_CUSTOM => 'Custom range',
                ])
                ->default(SalesDashboardFilters::PERIOD_LAST_30_DAYS)
                ->native(false)
                ->live(),
            DatePicker::make('customFrom')
                ->label('From')
                ->visible(fn (Get $get): bool => $get('period') === SalesDashboardFilters::PERIOD_CUSTOM),
            DatePicker::make('customUntil')
                ->label('Until')
                ->visible(fn (Get $get): bool => $get('period') === SalesDashboardFilters::PERIOD_CUSTOM),
            Select::make('employeeId')
                ->label('Salesperson')
                ->searchable()
                ->native(false)
                ->options(fn (): array => EmployeeProfile::query()
                    ->with('user:id,name')
                    ->get()
                    ->mapWithKeys(static fn (EmployeeProfile $employee): array => [$employee->id => (string) $employee->user?->name])
                    ->all()),
            Select::make('customerId')
                ->label('Customer')
                ->searchable()
                ->native(false)
                ->options(fn (): array => CustomerProfile::query()->orderBy('company_name')->pluck('company_name', 'id')->all()),
        ])->columns(4);
    }

    #[\Override]
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('filtersForm'),
            Grid::make(12)
                ->schema(fn (): array => $this->getWidgetsSchemaComponents($this->getDashboardWidgets())),
        ]);
    }

    /** @return array<class-string> */
    protected function getDashboardWidgets(): array
    {
        return [
            SalesKpiCards::class,
            SalesPerformanceChart::class,
            SalesFunnelWidget::class,
            RequiresAttentionWidget::class,
            QuotationPerformanceWidget::class,
            TopProductsChart::class,
            TopCustomersWidget::class,
            SalespersonPerformanceWidget::class,
            RecentSalesActivityWidget::class,
        ];
    }
}
