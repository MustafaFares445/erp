<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Actions\OrderActions;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

function orderActionCoverageOrder(float $quantity = 3.0): array
{
    $order = Order::factory()->create(['status' => OrderStatus::Draft]);
    $line = OrderLine::factory()->for($order)->create([
        'quantity' => $quantity,
        'unit_price' => '10.00',
        'tax_amount' => '0.00',
        'line_total' => number_format($quantity * 10, 2, '.', ''),
    ]);

    return [$order, $line];
}

it('executes confirm release short-close and close order actions end to end', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    [$order, $line] = orderActionCoverageOrder();

    $confirm = OrderActions::confirm()->getActionFunction();
    $release = OrderActions::release()->getActionFunction();
    $shortClose = OrderActions::shortClose()->getActionFunction();
    $close = OrderActions::close()->getActionFunction();

    expect($confirm)->toBeInstanceOf(Closure::class)
        ->and($release)->toBeInstanceOf(Closure::class)
        ->and($shortClose)->toBeInstanceOf(Closure::class)
        ->and($close)->toBeInstanceOf(Closure::class);

    $confirm($order);
    expect($order->refresh()->status)->toBe(OrderStatus::Confirmed);

    $release($order);
    expect($order->refresh()->status)->toBe(OrderStatus::Released);

    $shortClose($order, [
        'lines' => [
            'not-an-array',
            ['order_line_id' => 'bad', 'quantity' => 1],
            ['order_line_id' => $line->getKey(), 'quantity' => 'bad'],
            ['order_line_id' => $line->getKey(), 'quantity' => 3],
        ],
        'reason' => 'Coverage short close',
    ]);

    expect((float) $line->refresh()->short_closed_base_quantity)->toBe(3.0);

    $close($order);
    expect($order->refresh()->status)->toBe(OrderStatus::Closed);
});

it('executes cancel order action and validates raw cancellation reason', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $order = Order::factory()->create(['status' => OrderStatus::Draft]);
    $cancel = OrderActions::cancel()->getActionFunction();

    expect($cancel)->toBeInstanceOf(Closure::class)
        ->and(fn (): mixed => $cancel($order, ['reason' => 123]))
        ->toThrow(LogicException::class, 'cancellation reason');

    $cancel($order, ['reason' => 'Coverage cancellation']);

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);
});

it('covers the order action no-auth actor branch', function (): void {
    auth()->logout();
    $order = Order::factory()->create(['status' => OrderStatus::Draft]);

    $confirm = OrderActions::confirm()->getActionFunction();
    expect($confirm)->toBeInstanceOf(Closure::class);

    $confirm($order);

    expect($order->refresh()->status)->toBe(OrderStatus::Draft);
});

it('covers short-close line filtering when fulfillment progress is absent or fully consumed', function (): void {
    $method = new ReflectionMethod(OrderActions::class, 'shortCloseLines');

    $emptyOrder = Order::factory()->create(['status' => OrderStatus::Released]);
    $syntheticLine = new OrderLine;
    $syntheticLine->forceFill([
        'id' => 999999,
        'order_id' => $emptyOrder->getKey(),
        'product_variant_id' => 999999,
        'quantity' => 1,
        'base_quantity' => '1.000000',
        'short_closed_base_quantity' => '0.000000',
    ]);
    $emptyOrder->setRelation('lines', new Collection([$syntheticLine]));

    expect($method->invoke(null, $emptyOrder))->toBe([]);

    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);
    [$order, $line] = orderActionCoverageOrder();
    $order->forceFill(['status' => OrderStatus::Released])->saveQuietly();
    $line->forceFill(['short_closed_base_quantity' => $line->base_quantity])->saveQuietly();

    expect($method->invoke(null, $order->refresh()->load('lines')))->toBe([]);
});
