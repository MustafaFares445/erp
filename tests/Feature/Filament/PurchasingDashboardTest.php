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
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\SupplierConfirmation;
use App\Models\User;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

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

it('reports the operational purchasing KPIs the employee needs to act on', function (): void {
    // Open (non-terminal) orders.
    PurchaseOrder::factory()->count(2)->create();
    PurchaseOrder::factory()->count(3)->pendingApproval()->create();
    PurchaseOrder::factory()->accepted()->create();
    PurchaseOrder::factory()->sent()->create();

    // Terminal orders, excluded from the open count.
    PurchaseOrder::factory()->received()->create();
    PurchaseOrder::factory()->create(['status' => PurchaseOrderStatus::Closed, 'closed_at' => now()]);
    PurchaseOrder::factory()->cancelled()->create();

    // This-month spend: two orders dated this month with a non-zero amount,
    // and one dated last month that must be excluded from the sum.
    PurchaseOrder::factory()->create(['ordered_at' => now()->toDateString(), 'total_amount' => '1000.00']);
    PurchaseOrder::factory()->create(['ordered_at' => now()->toDateString(), 'total_amount' => '500.50']);
    PurchaseOrder::factory()->create(['ordered_at' => now()->subMonth()->startOfMonth()->toDateString(), 'total_amount' => '999.00']);

    PurchaseOrder::factory()->sent()->count(2)->create()->each(function (PurchaseOrder $order): void {
        SupplierConfirmation::factory()->create([
            'purchase_order_id' => $order->getKey(),
            'supplier_id' => $order->supplier_id,
        ]);
    });
    SupplierConfirmation::factory()->confirmed()->create();
    SupplierConfirmation::factory()->rejected()->create();

    $widget = app(PurchasingStatistics::class);
    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);
    $values = array_map(fn ($stat): mixed => $stat->getValue(), $stats);

    expect(array_slice($values, 0, 6))->toBe(['0', '3', '2', '0', '0', '1'])
        ->and($stats[0]->getDescription())->toBe('0 inventory · 0 sales needs · 0.00 inventory units')
        ->and($stats[5]->getDescription())->toBe('Received goods with a missing or draft supplier bill')
        ->and(count($stats))->toBeGreaterThanOrEqual(11);
});

it('uses a line chart for the six-month spend trend', function (): void {
    $widget = app(PurchasingSpendTrend::class);

    expect(new ReflectionMethod($widget, 'getType')->invoke($widget))->toBe('line');
});

it('buckets PO spend by month for the trailing six months', function (): void {
    PurchaseOrder::factory()->create(['ordered_at' => Carbon::now()->startOfMonth()->toDateString(), 'total_amount' => '100.00']);
    PurchaseOrder::factory()->create(['ordered_at' => Carbon::now()->startOfMonth()->toDateString(), 'total_amount' => '50.00']);
    PurchaseOrder::factory()->create(['ordered_at' => Carbon::now()->startOfMonth()->subMonths(2)->toDateString(), 'total_amount' => '75.00']);
    PurchaseOrder::factory()->create(['ordered_at' => Carbon::now()->startOfMonth()->subMonths(9)->toDateString(), 'total_amount' => '999.00']);

    $widget = app(PurchasingSpendTrend::class);
    $data = new ReflectionMethod($widget, 'getData')->invoke($widget);

    expect($data['labels'])->toHaveCount(6)
        ->and($data['labels'][5])->toBe(Carbon::now()->startOfMonth()->format('M Y'))
        ->and($data['datasets'][0]['label'])->toBe('PO spend · AED')
        ->and($data['datasets'][0]['data'][5])->toBe(150.0)
        ->and($data['datasets'][0]['data'][3])->toBe(75.0)
        ->and(array_sum($data['datasets'][0]['data']))->toBe(225.0);
});

it('counts only actionable sent supplier responses and active backorders', function (): void {
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

    $activeBackorder = SupplierConfirmation::factory()->create([
        'purchase_order_id' => $sent->getKey(),
        'supplier_id' => $sent->supplier_id,
        'confirmation_status' => SupplierConfirmationStatus::Partial,
    ]);
    $activeBackorderItem = $activeBackorder->items()->create([
        'product_variant_id' => ProductVariant::factory()->create()->getKey(),
        'requested_quantity' => '2.000',
        'requested_base_quantity' => '2.000000',
        'confirmed_base_quantity' => '1.000000',
        'backordered_base_quantity' => '1.000000',
    ]);
    $activeBackorderItem->forceFill(['confirmation_status' => SupplierConfirmationStatus::Partial])->save();

    $closed = PurchaseOrder::factory()->create([
        'status' => PurchaseOrderStatus::Closed,
        'closed_at' => now(),
    ]);
    $closedBackorder = SupplierConfirmation::factory()->create([
        'purchase_order_id' => $closed->getKey(),
        'supplier_id' => $closed->supplier_id,
        'confirmation_status' => SupplierConfirmationStatus::Partial,
    ]);
    $closedBackorderItem = $closedBackorder->items()->create([
        'product_variant_id' => ProductVariant::factory()->create()->getKey(),
        'requested_quantity' => '2.000',
        'requested_base_quantity' => '2.000000',
        'confirmed_base_quantity' => '1.000000',
        'backordered_base_quantity' => '1.000000',
    ]);
    $closedBackorderItem->forceFill(['confirmation_status' => SupplierConfirmationStatus::Partial])->save();

    $stats = new ReflectionMethod(app(PurchasingStatistics::class), 'getStats')->invoke(app(PurchasingStatistics::class));

    expect((int) $stats[2]->getValue())->toBe(1)
        ->and($stats[2]->getDescription())->toBe('Sent POs still waiting for a supplier response')
        ->and((int) $stats[6]->getValue())->toBe(1)
        ->and($stats[6]->getDescription())->toBe('Active Purchase Orders with supplier quantity still backordered');
});
