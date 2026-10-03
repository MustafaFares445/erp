<?php

declare(strict_types=1);

use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchasePermission;
use App\Enums\SupplierConfirmationStatus;
use App\Filament\Pages\PurchasingDashboard;
use App\Filament\Widgets\PurchasingAttentionQueue;
use App\Filament\Widgets\PurchasingOpenStageChart;
use App\Filament\Widgets\PurchasingSpendTrend;
use App\Filament\Widgets\PurchasingStatistics;
use App\Filament\Widgets\PurchasingUpcomingReceipts;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierConfirmation;
use App\Models\User;
use App\Support\MoneyFormatter;
use Database\Seeders\PurchasePermissionSeeder;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new PurchasePermissionSeeder)->run();
});

it('denies dashboard access without a purchasing permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    expect(PurchasingDashboard::canAccess())->toBeFalse();
});

it('grants dashboard access with the order view permission', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(PurchasePermission::OrderView->value);

    $this->actingAs($user);

    expect(PurchasingDashboard::canAccess())->toBeTrue();
});

it('shows operational dashboard widgets to buyers and manager analytics only to approvers', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(PurchasingStatistics::canView())->toBeFalse()
        ->and(PurchasingAttentionQueue::canView())->toBeFalse()
        ->and(PurchasingUpcomingReceipts::canView())->toBeFalse()
        ->and(PurchasingOpenStageChart::canView())->toBeFalse()
        ->and(PurchasingSpendTrend::canView())->toBeFalse();

    $user->givePermissionTo(PurchasePermission::OrderView->value);

    expect(PurchasingStatistics::canView())->toBeTrue()
        ->and(PurchasingAttentionQueue::canView())->toBeTrue()
        ->and(PurchasingUpcomingReceipts::canView())->toBeTrue()
        ->and(PurchasingOpenStageChart::canView())->toBeTrue()
        ->and(PurchasingSpendTrend::canView())->toBeFalse();

    $user->givePermissionTo(PurchasePermission::OrderApprove->value);

    expect(PurchasingSpendTrend::canView())->toBeTrue();
});

it('reports spend, sourcing backlog, approvals and overdue deliveries', function (): void {
    PurchaseOrder::factory()->count(3)->pendingApproval()->create(['total_amount' => '0']);
    PurchaseOrder::factory()->create(['status' => PurchaseOrderStatus::Accepted, 'expected_at' => today()->subDay(), 'total_amount' => '0']);
    PurchaseOrder::factory()->received()->create(['expected_at' => today()->subDay(), 'total_amount' => '0']);

    // Spend in the default currency only: two orders this period, one in the
    // previous window, and one in another currency that is never summed.
    PurchaseOrder::factory()->create(['ordered_at' => now()->toDateString(), 'total_amount' => '1000.00']);
    PurchaseOrder::factory()->create(['ordered_at' => now()->toDateString(), 'total_amount' => '500.00']);
    PurchaseOrder::factory()->create(['ordered_at' => now()->subDays(40)->toDateString(), 'total_amount' => '750.00']);
    PurchaseOrder::factory()->create(['ordered_at' => now()->toDateString(), 'total_amount' => '999.00', 'currency_code' => 'USD']);

    $stats = purchasingStats();

    expect($stats)->toHaveCount(4)
        ->and(array_map(fn (Stat $stat): mixed => $stat->getValue(), $stats))->toBe([
            MoneyFormatter::formatAmount(1500, 'AED'),
            '0',
            '3',
            '1',
        ])
        ->and($stats[0]->getLabel())->toBe('PO spend (AED)')
        ->and((string) $stats[0]->getDescription())->toBe('100.0% increase')
        ->and($stats[0]->getColor())->toBe('danger')
        ->and(array_sum($stats[0]->getChart() ?? []))->toBe(1500.0)
        ->and($stats[1]->getDescription())->toBe('0 inventory · 0 sales needs · 0 inventory units')
        ->and($stats[2]->getColor())->toBe('warning')
        ->and($stats[3]->getColor())->toBe('danger');
});

it('narrows purchasing KPIs, charts and tables to the selected supplier', function (): void {
    $supplier = Supplier::factory()->create();
    $mine = PurchaseOrder::factory()->pendingApproval()->create(['supplier_id' => $supplier->id, 'total_amount' => '200.00']);
    $theirs = PurchaseOrder::factory()->pendingApproval()->create(['total_amount' => '900.00']);

    $stats = purchasingStats(['supplierId' => $supplier->id]);

    expect($stats[0]->getValue())->toBe(MoneyFormatter::formatAmount(200, 'AED'))
        ->and($stats[2]->getValue())->toBe('1');

    $chart = app(PurchasingOpenStageChart::class);
    $chart->pageFilters = ['supplierId' => $supplier->id];

    expect(new ReflectionMethod($chart, 'getData')->invoke($chart)['datasets'][0]['data'][0])->toBe(1);

    $this->actingAs(purchasingViewer(PurchasePermission::OrderView));

    Livewire::test(PurchasingAttentionQueue::class, ['pageFilters' => ['supplierId' => $supplier->id]])
        ->assertSee('Needs your attention')
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('charts default-currency spend for the selected and previous period', function (): void {
    PurchaseOrder::factory()->create(['ordered_at' => now()->toDateString(), 'total_amount' => '100.00']);
    PurchaseOrder::factory()->create(['ordered_at' => now()->toDateString(), 'total_amount' => '50.00']);
    PurchaseOrder::factory()->create(['ordered_at' => now()->subDays(35)->toDateString(), 'total_amount' => '75.00']);
    PurchaseOrder::factory()->create(['ordered_at' => now()->toDateString(), 'total_amount' => '999.00', 'currency_code' => 'USD']);

    $widget = app(PurchasingSpendTrend::class);
    $data = new ReflectionMethod($widget, 'getData')->invoke($widget);

    expect(new ReflectionMethod($widget, 'getType')->invoke($widget))->toBe('line')
        ->and($widget->getHeading())->toBe('Purchase spend (AED)')
        ->and($data['labels'])->toHaveCount(30)
        ->and(array_sum($data['datasets'][0]['data']))->toBe(150.0)
        ->and(array_sum($data['datasets'][1]['data']))->toBe(75.0);
});

it('counts only sent purchase orders as waiting on the supplier', function (): void {
    $unsent = PurchaseOrder::factory()->accepted()->create();
    SupplierConfirmation::factory()->create([
        'purchase_order_id' => $unsent->getKey(),
        'supplier_id' => $unsent->supplier_id,
        'confirmation_status' => SupplierConfirmationStatus::Pending,
    ]);

    $sent = PurchaseOrder::factory()->sent()->create();
    SupplierConfirmation::factory()->create([
        'purchase_order_id' => $sent->getKey(),
        'supplier_id' => $sent->supplier_id,
        'confirmation_status' => SupplierConfirmationStatus::Pending,
    ]);

    $chart = app(PurchasingOpenStageChart::class);
    $data = new ReflectionMethod($chart, 'getData')->invoke($chart);

    expect($data['labels'])->toBe(['Approval', 'Ready to send', 'Supplier', 'Receiving', 'Accounting'])
        ->and($data['datasets'][0]['data'][1])->toBe(1)
        ->and($data['datasets'][0]['data'][2])->toBe(1);
});

it('lays out the purchasing dashboard in aligned pairs and gives the stage chart the row without approve access', function (): void {
    expect(new ReflectionMethod(PurchasingDashboard::class, 'getDashboardWidgets')->invoke(new PurchasingDashboard))->toBe([
        PurchasingStatistics::class,
        [PurchasingSpendTrend::class, PurchasingOpenStageChart::class],
        [PurchasingAttentionQueue::class, PurchasingUpcomingReceipts::class],
    ]);

    $this->actingAs(purchasingViewer(PurchasePermission::OrderView));

    $resolved = new ReflectionMethod(PurchasingDashboard::class, 'resolveDashboardWidgets')->invoke(new PurchasingDashboard);

    expect($resolved[1])->toBeInstanceOf(WidgetConfiguration::class)
        ->and($resolved[1]->widget)->toBe(PurchasingOpenStageChart::class);

    Livewire::test(PurchasingDashboard::class)
        ->assertSuccessful()
        ->assertSee('Supplier');
});

/**
 * @param  array<string, mixed>  $filters
 * @return list<Stat>
 */
function purchasingStats(array $filters = []): array
{
    $widget = app(PurchasingStatistics::class);
    $widget->pageFilters = $filters;

    /** @var list<Stat> */
    return new ReflectionMethod($widget, 'getStats')->invoke($widget);
}

function purchasingViewer(PurchasePermission ...$permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(array_map(static fn (PurchasePermission $permission): string => $permission->value, $permissions));

    return $user;
}
