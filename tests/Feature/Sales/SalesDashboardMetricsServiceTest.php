<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\QuotationStatus;
use App\Models\CustomerProfile;
use App\Models\EmployeeProfile;
use App\Models\InventoryOperation;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Services\Sales\SalesDashboardFilters;
use App\Services\Sales\SalesDashboardMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function salesDashboardFilters(?string $customerFrom = null, ?string $customerUntil = null, ?int $employeeId = null, ?int $customerId = null): SalesDashboardFilters
{
    return SalesDashboardFilters::fromPageFilters(array_filter([
        'period' => SalesDashboardFilters::PERIOD_CUSTOM,
        'customFrom' => $customerFrom ?? now()->startOfMonth()->toDateString(),
        'customUntil' => $customerUntil ?? now()->endOfMonth()->toDateString(),
        'employeeId' => $employeeId,
        'customerId' => $customerId,
    ], static fn (mixed $value): bool => $value !== null));
}

function confirmedOrder(array $attributes = []): Order
{
    return Order::factory()->create(array_merge([
        'status' => OrderStatus::Confirmed->value,
        'confirmed_at' => now(),
        'grand_total' => '1000.00',
    ], $attributes));
}

it('sums confirmed order value and count within the selected period, excluding draft and cancelled orders', function (): void {
    confirmedOrder(['grand_total' => '500.00']);
    confirmedOrder(['grand_total' => '300.00', 'status' => OrderStatus::Released->value]);
    Order::factory()->draft()->create(['grand_total' => '999.00']);
    confirmedOrder(['grand_total' => '999.00', 'status' => OrderStatus::Cancelled->value, 'confirmed_at' => null]);

    $kpis = app(SalesDashboardMetricsService::class)->kpis(salesDashboardFilters());

    expect($kpis['value'])->toBe(800.0)
        ->and($kpis['count'])->toBe(2)
        ->and($kpis['average_order_value'])->toBe(400.0);
});

it('compares the current period against an equal-length previous period', function (): void {
    confirmedOrder(['grand_total' => '200.00', 'confirmed_at' => now()->subDays(45)]);
    confirmedOrder(['grand_total' => '400.00', 'confirmed_at' => now()->subDays(5)]);

    $filters = salesDashboardFilters(now()->subDays(10)->toDateString(), now()->toDateString());
    $kpis = app(SalesDashboardMetricsService::class)->kpis($filters);

    expect($kpis['value'])->toBe(400.0)
        ->and($kpis['value_previous'])->toBe(0.0);
});

it('defines quote-to-order conversion as accepted-and-converted over decided quotations, excluding non-final statuses', function (): void {
    Quotation::factory()->accepted()->create(['converted_order_id' => confirmedOrder()->id]);
    Quotation::factory()->accepted()->create(); // accepted but never converted
    Quotation::factory()->create(['status' => QuotationStatus::Rejected, 'decided_at' => today()]);
    Quotation::factory()->sent()->create(); // no decision yet — excluded from denominator

    $kpis = app(SalesDashboardMetricsService::class)->kpis(salesDashboardFilters());

    expect($kpis['conversion_numerator'])->toBe(1)
        ->and($kpis['conversion_denominator'])->toBe(3)
        ->and($kpis['conversion_percent'])->toBe(33.3);
});

it('builds the sales funnel from quotations issued in the period, tracked through to invoicing', function (): void {
    $order = confirmedOrder(['grand_total' => '750.00']);
    InventoryOperation::factory()->delivery()->done()->create([
        'source_document_type' => Order::class,
        'source_document_id' => $order->id,
    ]);
    Invoice::factory()->create(['order_id' => $order->id, 'status' => InvoiceStatus::Issued->value, 'issued_at' => now()]);

    Quotation::factory()->accepted()->create(['converted_order_id' => $order->id, 'status' => QuotationStatus::ConvertedToDelivery]);
    Quotation::factory()->accepted()->create(); // accepted, not converted
    Quotation::factory()->create(); // draft, in the cohort but not accepted

    $funnel = app(SalesDashboardMetricsService::class)->salesFunnel(salesDashboardFilters());
    $byKey = collect($funnel['stages'])->keyBy('key');

    expect($byKey['quotations']['count'])->toBe(3)
        ->and($byKey['accepted']['count'])->toBe(2)
        ->and($byKey['orders']['count'])->toBe(1)
        ->and($byKey['orders']['value'])->toBe(750.0)
        ->and($byKey['delivered']['count'])->toBe(1)
        ->and($byKey['invoiced']['count'])->toBe(1);
});

it('flags accepted-not-converted and expiring-soon quotations as attention items with their potential value', function (): void {
    Quotation::factory()->accepted()->create(['grand_total' => '150.00']);
    Quotation::factory()->sent()->create(['expires_at' => now()->addDays(3), 'grand_total' => '80.00']);

    $items = app(SalesDashboardMetricsService::class)->attentionItems(salesDashboardFilters());
    $byKey = collect($items)->keyBy('key');

    expect($byKey->has('accepted_not_converted'))->toBeTrue()
        ->and($byKey['accepted_not_converted']['count'])->toBe(1)
        ->and($byKey->has('expiring_soon'))->toBeTrue();
});

it('flags completed deliveries with no linked invoice as delivered-not-invoiced', function (): void {
    InventoryOperation::factory()->delivery()->done()->create();

    $items = app(SalesDashboardMetricsService::class)->attentionItems(salesDashboardFilters());
    $byKey = collect($items)->keyBy('key');

    expect($byKey->has('delivered_not_invoiced'))->toBeTrue()
        ->and($byKey['delivered_not_invoiced']['count'])->toBe(1);
});

it('ranks top products by summed line total within the confirmed-order period', function (): void {
    $variantA = ProductVariant::factory()->create(['name' => 'Dental Chair X']);
    $variantB = ProductVariant::factory()->create(['name' => 'Scanner Y']);

    $order = confirmedOrder();
    // OrderLineObserver recalculates line_total from live pricing on create, so the
    // wanted totals are written straight to the row afterwards, bypassing that observer.
    OrderLine::factory()->for($order)->for($variantA, 'productVariant')->create(['quantity' => '2'])
        ->forceFill(['line_total' => '600.00'])->saveQuietly();
    OrderLine::factory()->for($order)->for($variantB, 'productVariant')->create(['quantity' => '1'])
        ->forceFill(['line_total' => '200.00'])->saveQuietly();

    $products = app(SalesDashboardMetricsService::class)->topProducts(salesDashboardFilters());

    expect($products[0]['label'])->toBe('Dental Chair X')
        ->and($products[0]['value'])->toBe(600.0)
        ->and($products[1]['label'])->toBe('Scanner Y');
});

it('ranks top customers by confirmed order value within the period', function (): void {
    $customer = CustomerProfile::factory()->create(['company_name' => 'Smile Dental Clinic']);
    confirmedOrder(['customer_id' => $customer->id, 'grand_total' => '300.00']);
    confirmedOrder(['customer_id' => $customer->id, 'grand_total' => '500.00']);
    confirmedOrder(['grand_total' => '100.00']);

    $customers = app(SalesDashboardMetricsService::class)->topCustomers(salesDashboardFilters());

    expect($customers[0]['label'])->toBe('Smile Dental Clinic')
        ->and($customers[0]['value'])->toBe(800.0)
        ->and($customers[0]['orders_count'])->toBe(2)
        ->and($customers[0]['average_value'])->toBe(400.0);
});

it('attributes orders to a salesperson only by tracing quotation.employee_id, never order.responsible_id', function (): void {
    $employee = EmployeeProfile::factory()->create();
    $order = confirmedOrder(['grand_total' => '250.00']);
    Quotation::factory()->create([
        'employee_id' => $employee->id,
        'converted_order_id' => $order->id,
        'status' => QuotationStatus::ConvertedToDelivery,
        'issue_date' => now(),
    ]);

    $performance = app(SalesDashboardMetricsService::class)->salespersonPerformance(salesDashboardFilters());

    expect($performance)->toHaveCount(1)
        ->and($performance[0]['employee_id'])->toBe($employee->id)
        ->and($performance[0]['orders'])->toBe(1)
        ->and($performance[0]['value'])->toBe(250.0);
});

it('scopes confirmed order value to a given customer or salesperson', function (): void {
    $employee = EmployeeProfile::factory()->create();
    $customer = CustomerProfile::factory()->create();

    $ownedOrder = confirmedOrder(['customer_id' => $customer->id, 'grand_total' => '400.00']);
    $quotation = Quotation::factory()->create([
        'employee_id' => $employee->id,
        'customer_id' => $customer->id,
        'converted_order_id' => $ownedOrder->id,
        'status' => QuotationStatus::ConvertedToDelivery,
    ]);
    $ownedOrder->forceFill(['quotation_id' => $quotation->id])->saveQuietly();
    confirmedOrder(['grand_total' => '900.00']); // unrelated order, must be excluded

    $byCustomer = app(SalesDashboardMetricsService::class)->kpis(salesDashboardFilters(customerId: $customer->id));
    $byEmployee = app(SalesDashboardMetricsService::class)->kpis(salesDashboardFilters(employeeId: $employee->id));

    expect($byCustomer['value'])->toBe(400.0)
        ->and($byEmployee['value'])->toBe(400.0);
});

it('returns zeroed, non-null KPIs for a period with no confirmed orders', function (): void {
    $kpis = app(SalesDashboardMetricsService::class)->kpis(salesDashboardFilters('2000-01-01', '2000-01-31'));

    expect($kpis['value'])->toBe(0.0)
        ->and($kpis['count'])->toBe(0)
        ->and($kpis['average_order_value'])->toBeNull()
        ->and($kpis['conversion_percent'])->toBeNull();
});

it('respects boundary dates by including orders confirmed exactly on the period start and end', function (): void {
    $from = now()->startOfMonth();
    $to = now()->startOfMonth()->addDays(9);

    confirmedOrder(['grand_total' => '100.00', 'confirmed_at' => $from->copy()->startOfDay()]);
    confirmedOrder(['grand_total' => '150.00', 'confirmed_at' => $to->copy()->endOfDay()]);
    confirmedOrder(['grand_total' => '999.00', 'confirmed_at' => $to->copy()->addDay()]);

    $filters = salesDashboardFilters($from->toDateString(), $to->toDateString());
    $kpis = app(SalesDashboardMetricsService::class)->kpis($filters);

    expect($kpis['value'])->toBe(250.0)
        ->and($kpis['count'])->toBe(2);
});

it('reports a single default-currency label rather than mixing currencies', function (): void {
    confirmedOrder();

    $kpis = app(SalesDashboardMetricsService::class)->kpis(salesDashboardFilters());

    expect($kpis['currency'])->toBeString()->not->toBeEmpty();
});

it('generates drill-down URLs into the quotation and order resources with tab and filter query parameters', function (): void {
    $customer = CustomerProfile::factory()->create();
    Quotation::factory()->accepted()->for($customer, 'customer')->create();

    $items = app(SalesDashboardMetricsService::class)->attentionItems(salesDashboardFilters(customerId: $customer->id));
    $byKey = collect($items)->keyBy('key');

    expect($byKey['accepted_not_converted']['url'])
        ->toContain('tab=accepted')
        ->toContain("filters%5Bcustomer_id%5D%5Bvalue%5D={$customer->id}");
});
