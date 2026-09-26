<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
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
