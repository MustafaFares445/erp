<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Models\Currency;
use App\Models\CustomerProfile;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Sales\DocumentNumberGenerator;
use App\Services\Sales\OrderNextActionResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves every customer-order next action branch', function (): void {
    $resolver = app(OrderNextActionResolver::class);
    $order = Order::factory()->draft()->create();

    expect($resolver->resolve($order, [])['label'])->toBe('Confirm order');
    $order->forceFill(['status' => OrderStatus::Confirmed])->save();
    expect($resolver->resolve($order->refresh(), [])['label'])->toBe('Release to Logistics');
    foreach ([OrderStatus::Cancelled, OrderStatus::Closed] as $terminal) {
        $order->forceFill(['status' => $terminal])->save();
        expect($resolver->resolve($order->refresh(), [])['label'])->toBe('No action required');
    }

    $order->forceFill(['status' => OrderStatus::Released])->save();
    $cases = [
        [['procurement_outstanding' => 1], 'Purchasing', 'Resolve supply requirement'],
        [['remaining' => 1], 'Logistics', 'Allocate remaining demand'],
        [['ready' => 1], 'Logistics', 'Dispatch goods'],
        [['dispatched' => 2, 'arrived' => 1], 'Customer/System', 'Confirm shipment arrival'],
        [['dispatched' => 2, 'arrived' => 2, 'invoiced' => 1], 'Accounting', 'Create invoice'],
        [['outstanding_receivable' => 1], 'Accounting', 'Collect or record payment'],
        [[], 'Sales', 'Close order'],
    ];
    foreach ($cases as [$facts, $owner, $label]) {
        $result = $resolver->resolve($order->refresh(), $facts);
        expect($result['owner'])->toBe($owner)->and($result['label'])->toBe($label);
    }
});

it('reads warehouse balances including missing stock', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    expect($warehouse->currentOnHand($variant->getKey()))->toBe(0.0)
        ->and($warehouse->currentAvailable($variant->getKey()))->toBe(0.0);
    InventoryStock::factory()->for($variant, 'productVariant')->for($warehouse, 'warehouse')->create([
        'on_hand_quantity' => '12.500000',
        'reserved_quantity' => '2.500000',
        'available_quantity' => '10.000000',
    ]);
    expect($warehouse->currentOnHand($variant->getKey()))->toBe(12.5)
        ->and($warehouse->currentAvailable($variant->getKey()))->toBe(10.0);
});

it('enforces currency default lifecycle rules', function (): void {
    $first = Currency::query()->create(['code' => 'xaa', 'name' => ' Coverage Alpha ', 'is_active' => false, 'is_default' => true]);
    expect($first->code)->toBe('XAA')->and($first->name)->toBe('Coverage Alpha')->and($first->is_active)->toBeTrue();

    $second = Currency::query()->create(['code' => 'xbb', 'name' => 'Coverage Beta', 'is_active' => true, 'is_default' => true]);
    expect($first->refresh()->is_default)->toBeFalse()->and($second->is_default)->toBeTrue();
    expect(fn () => $second->delete())->toThrow(DomainException::class);

    $second->forceFill(['is_default' => false])->save();
    expect($second->refresh()->is_default)->toBeTrue();
});

it('prevents allocation changes after the payment is posted', function (): void {
    $customer = CustomerProfile::factory()->create();
    $method = PaymentMethod::factory()->create();
    $payment = Payment::query()->forceCreate([
        'payment_number' => 'PAY-MODEL-COVERAGE', 'customer_id' => $customer->getKey(),
        'payment_method_id' => $method->getKey(), 'amount' => '100.00', 'currency' => 'AED',
        'payment_date' => today(), 'status' => 'posted', 'posted_at' => now(),
    ]);
    $invoice = Invoice::factory()->for($customer, 'customer')->create(['issued_at' => now()]);
    $allocation = PaymentAllocation::query()->forceCreate([
        'payment_id' => $payment->getKey(),
        'invoice_id' => $invoice->getKey(),
        'amount' => '10.00',
    ]);

    $allocation->amount = '11.00';

    expect(fn () => $allocation->save())->toThrow(DomainException::class, 'immutable');
    $allocation->refresh();
    expect(fn () => $allocation->delete())->toThrow(DomainException::class, 'cannot be deleted');
});

it('synchronizes product units and detects stock history', function (): void {
    $product = Product::factory()->create();
    $unitA = Unit::factory()->create();
    $unitB = Unit::factory()->create();

    $product->syncUnits([$unitA->getKey(), 'invalid', $unitB->getKey(), $unitA->getKey()], 999999);
    expect($product->units()->count())->toBe(2)
        ->and((int) $product->units()->wherePivot('is_default', true)->firstOrFail()->getKey())->toBe($unitA->getKey());

    $product->addAllowedUnit($unitA);
    expect($product->units()->count())->toBe(2)
        ->and($product->hasStockHistory())->toBeFalse();

    expect(fn () => $product->addAllowedUnit(new Unit))->toThrow(LogicException::class);
    $variant = ProductVariant::factory()->for($product, 'product')->create();
    InventoryStock::factory()->for($variant, 'productVariant')->create([
        'on_hand_quantity' => 1,
        'reserved_quantity' => 0,
        'available_quantity' => 1,
    ]);
    expect($product->hasStockHistory())->toBeTrue();
});

it('parses document-number sequences and ignores malformed values', function (): void {
    $builder = Mockery::mock(Builder::class);
    $builder->shouldReceive('whereNotNull')->once()->with('number')->andReturnSelf();
    $builder->shouldReceive('lockForUpdate')->once()->andReturnSelf();
    $builder->shouldReceive('pluck')->once()->with('number')->andReturn(collect([
        12,
        'OTHER-000099',
        'DOC-',
        'DOC-ABC',
        'DOC-000007',
        'DOC-000012',
    ]));

    $number = app(DocumentNumberGenerator::class)->next($builder, 'number', 'DOC-', 6);
    expect($number)->toBe('DOC-000013');
});
