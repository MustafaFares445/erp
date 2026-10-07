<?php

declare(strict_types=1);

use App\Enums\OrderCloseSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuotationStatus;
use App\Models\Currency;
use App\Models\CustomerProfile;
use App\Models\EmployeeProfile;
use App\Models\InventoryOperation;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\Shipment;
use App\Services\Sales\OrderCompletionService;
use App\Services\Sales\OrderFinancialProjectionService;
use App\Services\Sales\SalesDashboardFilters;
use App\Services\Sales\SalesDashboardMetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function zeroIssuedInvoice(Order $order, CustomerProfile $customer): Invoice
{
    return Invoice::factory()->create([
        'order_id' => $order->getKey(),
        'customer_id' => $customer->getKey(),
        'subtotal' => '0.00',
        'tax_total' => '0.00',
        'total_amount' => '0.00',
        'amount_paid' => '0.00',
        'status' => 'issued',
        'issued_at' => now(),
    ]);
}

it('covers customer completion guards, evidence validation, and successful close', function (): void {
    Storage::fake('local');
    Event::fake();

    $service = app(OrderCompletionService::class);
    $customer = CustomerProfile::factory()->create();

    $closed = Order::factory()->for($customer, 'customer')->create(['status' => OrderStatus::Closed->value]);
    expect($service->closeByCustomer($customer, $closed, []))->status->toBe(OrderStatus::Closed);

    $otherCustomer = CustomerProfile::factory()->create();
    $foreignOrder = Order::factory()->for($otherCustomer, 'customer')->create();
    expect(fn (): Order => $service->closeByCustomer($customer, $foreignOrder, []))
        ->toThrow(DomainException::class, 'does not belong');

    $confirmed = Order::factory()->for($customer, 'customer')->confirmed()->create();
    expect(fn (): Order => $service->closeByCustomer($customer, $confirmed, []))
        ->toThrow(DomainException::class, 'must be released');

    $unsettled = Order::factory()->for($customer, 'customer')->create();
    expect(fn (): Order => $service->closeByCustomer($customer, $unsettled, []))
        ->toThrow(DomainException::class, 'not yet eligible for completion');

    $eligible = Order::factory()->for($customer, 'customer')->create();
    zeroIssuedInvoice($eligible, $customer);

    expect(fn (): Order => $service->closeByCustomer($customer, $eligible, []))
        ->toThrow(DomainException::class, 'requires at least one evidence file');

    $invalid = UploadedFile::fake()->create('proof.txt', 1, 'text/plain');
    expect(fn (): Order => $service->closeByCustomer($customer, $eligible, [$invalid]))
        ->toThrow(DomainException::class, 'must be a JPEG, PNG, WEBP or PDF');

    $evidenceValidator = new ReflectionMethod(OrderCompletionService::class, 'assertValidEvidence');
    $tooLarge = UploadedFile::fake()->create('large.pdf', 16_000, 'application/pdf');
    expect(fn (): mixed => $evidenceValidator->invoke($service, $tooLarge))
        ->toThrow(DomainException::class, 'exceeds the maximum allowed size');

    $proof = UploadedFile::fake()->create('proof.pdf', 1, 'application/pdf');
    $result = $service->closeByCustomer($customer, $eligible, [$proof], 'Delivered in full.');

    expect($result->status)->toBe(OrderStatus::Closed)
        ->and($result->closed_by_source)->toBe(OrderCloseSource::Customer)
        ->and($result->completionConfirmation)->not->toBeNull()
        ->and($result->completionConfirmation?->getMedia('order-completion-evidence'))->toHaveCount(1);
});

it('covers automatic completion no-op branches and successful system close', function (): void {
    Event::fake();

    $service = app(OrderCompletionService::class);
    $customer = CustomerProfile::factory()->create();

    $terminal = Order::factory()->for($customer, 'customer')->create(['status' => OrderStatus::Cancelled->value]);
    expect($service->closeAutomatically($terminal)->status)->toBe(OrderStatus::Cancelled);

    $confirmed = Order::factory()->for($customer, 'customer')->confirmed()->create();
    expect($service->closeAutomatically($confirmed)->status)->toBe(OrderStatus::Confirmed);

    $notDue = Order::factory()->for($customer, 'customer')->create(['auto_close_due_at' => now()->addDay()]);
    expect($service->closeAutomatically($notDue)->status)->toBe(OrderStatus::Released);

    $ineligible = Order::factory()->for($customer, 'customer')->create(['auto_close_due_at' => now()->subMinute()]);
    expect($service->closeAutomatically($ineligible)->status)->toBe(OrderStatus::Released);

    $eligible = Order::factory()->for($customer, 'customer')->create(['auto_close_due_at' => now()->subMinute()]);
    zeroIssuedInvoice($eligible, $customer);

    $closed = $service->closeAutomatically($eligible);

    expect($closed->status)->toBe(OrderStatus::Closed)
        ->and($closed->closed_by_source)->toBe(OrderCloseSource::System)
        ->and($closed->closed_at)->not->toBeNull();
});

it('starts, preserves, and clears the completion window from physical fulfillment state', function (): void {
    Event::fake();

    $service = app(OrderCompletionService::class);
    $customer = CustomerProfile::factory()->create();

    $confirmed = Order::factory()->for($customer, 'customer')->confirmed()->create();
    expect($service->refreshCompletionWindow($confirmed)->completion_window_started_at)->toBeNull();

    $blocked = Order::factory()->for($customer, 'customer')->create([
        'completion_window_started_at' => now()->subDays(2),
        'auto_close_days_snapshot' => 3,
        'auto_close_due_at' => now()->addDay(),
    ]);
    $variant = ProductVariant::factory()->create();
    $line = OrderLine::factory()->for($blocked)->for($variant, 'productVariant')->create();
    $blocked->procurementRequirements()->create([
        'order_line_id' => $line->getKey(),
        'product_variant_id' => $variant->getKey(),
        'required_base_quantity' => '1.000000',
        'fulfilled_base_quantity' => '0.000000',
        'status' => 'open',
    ]);

    $cleared = $service->refreshCompletionWindow($blocked);
    expect($cleared->completion_window_started_at)->toBeNull()
        ->and($cleared->auto_close_days_snapshot)->toBeNull()
        ->and($cleared->auto_close_due_at)->toBeNull();

    $ready = Order::factory()->for($customer, 'customer')->create();
    $arrival = now()->subHour()->startOfSecond();
    Shipment::factory()->arrived()->create([
        'order_id' => $ready->getKey(),
        'confirmed_at' => $arrival,
    ]);

    $started = $service->refreshCompletionWindow($ready);
    expect($started->completion_window_started_at?->equalTo($arrival))->toBeTrue()
        ->and($started->auto_close_days_snapshot)->toBeInt()
        ->and($started->auto_close_due_at)->not->toBeNull();

    $unchanged = $service->refreshCompletionWindow($started);
    expect($unchanged->completion_window_started_at?->equalTo($arrival))->toBeTrue();
});

it('covers dashboard period presets, custom range inversion, and all granularities', function (): void {
    CarbonImmutable::setTestNow('2026-09-30 12:00:00');

    $today = SalesDashboardFilters::fromPageFilters(['period' => SalesDashboardFilters::PERIOD_TODAY]);
    $week = SalesDashboardFilters::fromPageFilters(['period' => SalesDashboardFilters::PERIOD_LAST_7_DAYS]);
    $month = SalesDashboardFilters::fromPageFilters(['period' => SalesDashboardFilters::PERIOD_THIS_MONTH]);
    $lastMonth = SalesDashboardFilters::fromPageFilters(['period' => SalesDashboardFilters::PERIOD_LAST_MONTH]);
    $quarter = SalesDashboardFilters::fromPageFilters(['period' => SalesDashboardFilters::PERIOD_THIS_QUARTER]);
    $year = SalesDashboardFilters::fromPageFilters(['period' => SalesDashboardFilters::PERIOD_THIS_YEAR]);
    $default = SalesDashboardFilters::fromPageFilters(['period' => new stdClass]);
    $custom = SalesDashboardFilters::fromPageFilters([
        'period' => SalesDashboardFilters::PERIOD_CUSTOM,
        'customFrom' => '2026-09-20',
        'customUntil' => '2026-09-10',
        'employeeId' => '42',
        'customerId' => 7,
    ]);
    $customWeekly = SalesDashboardFilters::fromPageFilters([
        'period' => SalesDashboardFilters::PERIOD_CUSTOM,
        'customFrom' => '2026-01-01',
        'customUntil' => '2026-03-01',
    ]);
    $customMonthly = SalesDashboardFilters::fromPageFilters([
        'period' => SalesDashboardFilters::PERIOD_CUSTOM,
        'customFrom' => '2026-01-01',
        'customUntil' => '2026-08-01',
    ]);
    $customDefaults = SalesDashboardFilters::fromPageFilters([
        'period' => SalesDashboardFilters::PERIOD_CUSTOM,
        'customFrom' => '',
        'customUntil' => null,
        'employeeId' => 'not-numeric',
    ]);

    expect($today->granularity)->toBe(SalesDashboardFilters::GRANULARITY_HOURLY)
        ->and($week->granularity)->toBe(SalesDashboardFilters::GRANULARITY_DAILY)
        ->and($month->from->day)->toBe(1)
        ->and($lastMonth->to->month)->toBe(8)
        ->and($quarter->granularity)->toBe(SalesDashboardFilters::GRANULARITY_WEEKLY)
        ->and($year->granularity)->toBe(SalesDashboardFilters::GRANULARITY_MONTHLY)
        ->and($default->from->toDateString())->toBe('2026-09-01')
        ->and($custom->from->toDateString())->toBe('2026-09-10')
        ->and($custom->to->toDateString())->toBe('2026-09-20')
        ->and($custom->employeeId)->toBe(42)
        ->and($custom->customerId)->toBe(7)
        ->and($customWeekly->granularity)->toBe(SalesDashboardFilters::GRANULARITY_WEEKLY)
        ->and($customMonthly->granularity)->toBe(SalesDashboardFilters::GRANULARITY_MONTHLY)
        ->and($customDefaults->employeeId)->toBeNull();

    CarbonImmutable::setTestNow();
});

it('covers order prepayment projection with and without posted provider payments', function (): void {
    Currency::query()->firstOrCreate(
        ['code' => 'USD'],
        ['name' => 'US Dollar', 'is_active' => true, 'is_default' => true],
    );

    $service = app(OrderFinancialProjectionService::class);
    $customer = CustomerProfile::factory()->create();
    $order = Order::factory()->for($customer, 'customer')->create(['grand_total' => '100.00']);

    $empty = $service->project($order);
    expect($empty->customerDepositCollected)->toBe(0.0)
        ->and($empty->issuedInvoiceCount)->toBe(0)
        ->and($empty->financiallySettled)->toBeFalse();

    $payment = Payment::factory()->create([
        'payment_number' => 'PAY-COV-037',
        'customer_id' => $customer->getKey(),
        'payment_method_id' => PaymentMethod::factory(),
        'amount' => '50.00',
        'currency' => 'USD',
        'payment_date' => now()->toDateString(),
        'status' => PaymentStatus::Posted->value,
        'posted_at' => now(),
    ]);
    PaymentTransaction::factory()->succeeded()->create([
        'customer_id' => $customer->getKey(),
        'purpose_type' => Order::class,
        'purpose_id' => $order->getKey(),
        'payment_id' => $payment->getKey(),
    ]);

    $projection = $service->project($order);

    expect($projection->customerDepositCollected)->toBe(50.0);
});

it('covers sales dashboard activity filters and private helpers', function (): void {
    $service = app(SalesDashboardMetricsService::class);
    $customer = CustomerProfile::factory()->create(['company_name' => 'Coverage Dental']);
    $employee = EmployeeProfile::factory()->create();

    Quotation::factory()->accepted()->create([
        'customer_id' => $customer->getKey(),
        'employee_id' => $employee->getKey(),
        'decided_at' => today(),
    ]);

    $order = Order::factory()->for($customer, 'customer')->create([
        'confirmed_at' => now(),
        'grand_total' => '25.00',
    ]);
    $quotation = Quotation::factory()->create([
        'customer_id' => $customer->getKey(),
        'employee_id' => $employee->getKey(),
        'converted_order_id' => $order->getKey(),
        'status' => QuotationStatus::ConvertedToOrder->value,
        'issue_date' => today(),
    ]);
    $order->forceFill(['quotation_id' => $quotation->getKey()])->saveQuietly();

    InventoryOperation::factory()->delivery()->done()->create([
        'customer_id' => $customer->getKey(),
        'completed_at' => now()->subMinute(),
    ]);
    Invoice::factory()->create([
        'order_id' => $order->getKey(),
        'customer_id' => $customer->getKey(),
        'issued_at' => now(),
        'status' => 'issued',
    ]);

    $filters = SalesDashboardFilters::fromPageFilters([
        'period' => SalesDashboardFilters::PERIOD_THIS_MONTH,
        'customerId' => $customer->getKey(),
        'employeeId' => $employee->getKey(),
    ]);
    $activity = $service->recentActivity($filters, 20);

    expect(collect($activity)->pluck('label')->implode(' '))
        ->toContain('Quotation')
        ->toContain('Order')
        ->toContain('Delivery')
        ->toContain('Invoice');

    $percentChange = new ReflectionMethod(SalesDashboardMetricsService::class, 'percentChange');
    $customerLabel = new ReflectionMethod(SalesDashboardMetricsService::class, 'customerLabel');

    expect($percentChange->invoke($service, 0.0, 0.0))->toBeNull()
        ->and($percentChange->invoke($service, 0.0, 5.0))->toBe(100.0)
        ->and($percentChange->invoke($service, 10.0, 15.0))->toBe(50.0)
        ->and($customerLabel->invoke($service, null, null))->toBe('Unknown customer')
        ->and($customerLabel->invoke($service, $customer, $customer->getKey()))->toBe('Coverage Dental');
});
