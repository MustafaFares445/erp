<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Services\Sales\OrderFinancialProjectionService;
use App\Services\Sales\SalesDashboardFilters;
use App\Services\Sales\SalesDashboardMetricsService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function coverage102Filters(): SalesDashboardFilters
{
    return SalesDashboardFilters::fromPageFilters([
        'period' => SalesDashboardFilters::PERIOD_CUSTOM,
        'customFrom' => now()->startOfMonth()->toDateString(),
        'customUntil' => now()->endOfMonth()->toDateString(),
    ]);
}

it('covers sales dashboard awaiting-fulfillment attention item', function (): void {
    Order::factory()->create([
        'status' => OrderStatus::Confirmed->value,
        'confirmed_at' => now(),
        'grand_total' => '432.10',
    ]);

    $items = app(SalesDashboardMetricsService::class)->attentionItems(coverage102Filters());
    $byKey = collect($items)->keyBy('key');

    expect($byKey)->toHaveKey('awaiting_fulfillment')
        ->and($byKey['awaiting_fulfillment']['count'])->toBe(1)
        ->and($byKey['awaiting_fulfillment']['priority'])->toBe(40)
        ->and($byKey['awaiting_fulfillment']['detail'])->toContain('432');
});

it('covers relation-loaded order prepayment aggregation with allocations', function (): void {
    $payment = new Payment;
    $payment->forceFill([
        'amount' => '100.00',
        'status' => PaymentStatus::Posted,
        'posted_at' => now(),
        'reversed_at' => null,
    ]);

    $allocation = new PaymentAllocation;
    $allocation->forceFill(['amount' => '40.00']);
    $payment->setRelation('allocations', new EloquentCollection([$allocation]));

    $transaction = new PaymentTransaction;
    $transaction->forceFill(['status' => PaymentTransactionStatus::Succeeded]);
    $transaction->setRelation('payment', $payment);

    $order = new Order;
    $order->setRelation('workflowPaymentTransactions', new EloquentCollection([$transaction]));

    $method = new ReflectionMethod(OrderFinancialProjectionService::class, 'orderPrepayments');
    $result = $method->invoke(app(OrderFinancialProjectionService::class), $order);

    expect($result)->toBe([100.0, 60.0]);
});
