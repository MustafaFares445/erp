<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\SalesPermission;
use App\Filament\Pages\SalesDashboard;
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
use App\Models\Order;
use App\Models\Quotation;
use App\Models\User;
use Database\Seeders\SalesPermissionSeeder;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SalesPermissionSeeder)->run();
});

function salesDashboardViewer(): User
{
    $user = User::factory()->admin()->create();
    $user->assignRole(DashboardRole::SalesOfficer->value);

    return $user;
}

it('renders the sales dashboard and every one of its widgets for an authorized user', function (): void {
    $customerA = CustomerProfile::factory()->create();
    $customerB = CustomerProfile::factory()->create();
    Order::factory()->create(['customer_id' => $customerA->id, 'grand_total' => '500.00']);
    Order::factory()->create(['customer_id' => $customerB->id, 'grand_total' => '300.00']);
    Quotation::factory()->accepted()->for($customerA, 'customer')->create();

    Livewire::actingAs(salesDashboardViewer())
        ->test(SalesDashboard::class)
        ->assertSuccessful();

    foreach ([
        SalesKpiCards::class,
        SalesPerformanceChart::class,
        SalesFunnelWidget::class,
        RequiresAttentionWidget::class,
        QuotationPerformanceWidget::class,
        TopProductsChart::class,
        TopCustomersWidget::class,
        RecentSalesActivityWidget::class,
    ] as $widget) {
        Livewire::actingAs(salesDashboardViewer())->test($widget)->assertSuccessful();
    }
});

it('scopes the confirmed order value KPI to the selected customer filter', function (): void {
    $customer = CustomerProfile::factory()->create();
    Order::factory()->create(['customer_id' => $customer->id, 'status' => 'confirmed', 'confirmed_at' => now(), 'grand_total' => '500.00']);
    Order::factory()->create(['status' => 'confirmed', 'confirmed_at' => now(), 'grand_total' => '900.00']);

    Livewire::actingAs(salesDashboardViewer())
        ->test(SalesKpiCards::class, ['pageFilters' => ['customerId' => $customer->id, 'period' => 'last_30_days']])
        ->assertSuccessful()
        ->assertSee('500');
});

it('hides salesperson performance when no quotation has an assigned salesperson', function (): void {
    $this->actingAs(salesDashboardViewer());

    Quotation::factory()->create();

    expect(SalespersonPerformanceWidget::canView())->toBeFalse();

    Quotation::factory()->create(['employee_id' => EmployeeProfile::factory()->create()->id]);

    expect(SalespersonPerformanceWidget::canView())->toBeTrue();
});

it('denies access to the sales dashboard for a user with no sales permissions', function (): void {
    $user = User::factory()->admin()->create();

    expect(SalesDashboard::canAccess())->toBeFalse();

    auth()->logout();
    auth()->login($user);

    expect(SalesDashboard::canAccess())->toBeFalse();
});

it('compares confirmed order value with the previous period and draws its sparkline', function (): void {
    Order::factory()->create(['status' => 'confirmed', 'confirmed_at' => now(), 'grand_total' => '200.00']);
    Order::factory()->create(['status' => 'confirmed', 'confirmed_at' => now()->subDays(35), 'grand_total' => '100.00']);

    $widget = Livewire::actingAs(salesDashboardViewer())
        ->test(SalesKpiCards::class, ['pageFilters' => ['period' => 'last_30_days']])
        ->instance();

    /** @var list<Stat> $stats */
    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);

    expect($stats)->toHaveCount(4)
        ->and((string) $stats[0]->getDescription())->toBe('100.0% increase')
        ->and($stats[0]->getColor())->toBe('success')
        ->and($stats[0]->getChart())->toHaveCount(30)
        ->and(array_sum($stats[1]->getChart() ?? []))->toBe(1)
        ->and($stats[2]->getChart())->toBeNull();
});

it('lists top customers by confirmed value in a paginated table', function (): void {
    $customer = CustomerProfile::factory()->create(['company_name' => 'Gulf Dental Group']);
    Order::factory()->create(['customer_id' => $customer->id, 'status' => 'confirmed', 'confirmed_at' => now(), 'grand_total' => '750.00']);

    Livewire::actingAs(salesDashboardViewer())
        ->test(TopCustomersWidget::class, ['pageFilters' => ['period' => 'last_30_days']])
        ->assertSee('Top customers')
        ->assertSee('Gulf Dental Group')
        ->assertSee('750.00');

    Livewire::actingAs(salesDashboardViewer())
        ->test(TopCustomersWidget::class, ['pageFilters' => ['period' => 'last_30_days', 'customerId' => CustomerProfile::factory()->create()->id]])
        ->assertDontSee('Gulf Dental Group')
        ->assertSee('Nothing to show for the selected filters.');
});

it('lists salesperson performance in a table', function (): void {
    $employee = EmployeeProfile::factory()->create();
    Quotation::factory()->create(['employee_id' => $employee->id, 'issue_date' => today()]);

    Livewire::actingAs(salesDashboardViewer())
        ->test(SalespersonPerformanceWidget::class, ['pageFilters' => ['period' => 'last_30_days']])
        ->assertSee('Salesperson performance')
        ->assertSee((string) $employee->user?->name)
        ->assertSee('1 quotations');
});

it('gives top customers the full row when salesperson performance is hidden', function (): void {
    $this->actingAs(salesDashboardViewer());

    $resolve = fn (): array => new ReflectionMethod(SalesDashboard::class, 'resolveDashboardWidgets')->invoke(new SalesDashboard);

    $promoted = collect($resolve())->first(fn (mixed $widget): bool => $widget instanceof WidgetConfiguration);

    expect($promoted?->widget)->toBe(TopCustomersWidget::class)
        ->and($promoted?->getProperties())->toBe(['spansFullWidth' => true]);

    Quotation::factory()->create(['employee_id' => EmployeeProfile::factory()->create()->id]);

    expect(collect($resolve())->contains(fn (mixed $widget): bool => $widget instanceof WidgetConfiguration))->toBeFalse();
});

it('resets the filter bar to the default period', function (): void {
    Livewire::actingAs(salesDashboardViewer())
        ->test(SalesDashboard::class)
        ->set('filters.period', 'this_year')
        ->assertSet('filters.period', 'this_year')
        ->callAction('resetFilters')
        ->assertSet('filters.period', 'last_30_days');
});

it('renders the sales dashboard in Arabic', function (): void {
    app()->setLocale('ar');

    Livewire::actingAs(salesDashboardViewer())
        ->test(TopCustomersWidget::class)
        ->assertSee('أفضل العملاء');

    Livewire::actingAs(salesDashboardViewer())
        ->test(SalesDashboard::class)
        ->assertSee('الفترة الزمنية');
});

it('translates chart empty states and gates widgets on the matching permission', function (): void {
    expect(app(SalesPerformanceChart::class)->getEmptyStateHeading())->toBe('No sales activity exists for the selected period.')
        ->and(app(TopProductsChart::class)->getEmptyStateHeading())->toBe('No product sales exist for the selected period.');

    $orderViewer = User::factory()->create();
    $orderViewer->givePermissionTo(SalesPermission::OrderView->value);
    $this->actingAs($orderViewer);

    expect(RequiresAttentionWidget::canView())->toBeTrue()
        ->and(RecentSalesActivityWidget::canView())->toBeTrue()
        ->and(SalespersonPerformanceWidget::canView())->toBeFalse();
});
