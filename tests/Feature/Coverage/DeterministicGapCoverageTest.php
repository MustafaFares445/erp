<?php

declare(strict_types=1);

use App\Casts\OrderStatusCast;
use App\Enums\OrderStatus;
use App\Enums\ResolvedPriceSource;
use App\Models\OrderLine;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseInboundLine;
use App\Models\PurchaseOrder;
use App\Models\Warehouse;
use App\Services\Purchasing\Exceptions\InvalidConfirmationTarget;
use App\Services\Purchasing\Exceptions\InvalidPurchaseInboundAllocation;
use App\Services\Purchasing\Exceptions\InvalidPurchaseInboundReceipt;
use App\Services\Purchasing\Exceptions\PurchaseOrderNotReceivable;
use App\Support\QuantityFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('covers purchasing exception factories', function (): void {
    $warehouse = Warehouse::factory()->create(['code' => 'WH-COV']);
    $order = PurchaseOrder::factory()->create();
    $line = PurchaseInboundLine::factory()->create();
    $allocation = PurchaseInboundAllocation::factory()->create();

    $exceptions = [
        InvalidPurchaseInboundReceipt::quantityNotPositive(),
        InvalidPurchaseInboundReceipt::duplicateAllocation((int) $allocation->getKey()),
        InvalidPurchaseInboundReceipt::allocationNotForOrder($allocation, $order),
        InvalidPurchaseInboundReceipt::unresolvedAllocationQuantity($allocation),
        InvalidPurchaseInboundReceipt::inactiveWarehouse($warehouse),
        InvalidPurchaseInboundReceipt::mixedWarehouses(),
        InvalidPurchaseInboundReceipt::allocationExceeded('2', '1'),
        InvalidPurchaseInboundReceipt::purchaseOrderLineExceeded('2', '1'),
        InvalidPurchaseInboundReceipt::nothingAvailable($order),
        InvalidPurchaseInboundReceipt::missingAllocationProvenance(),
        InvalidPurchaseInboundReceipt::warehouseMismatch($allocation),
        InvalidPurchaseInboundReceipt::purchaseOrderLineMismatch($allocation),
        InvalidPurchaseInboundReceipt::allocationOverReceived($allocation, '2', '1'),
        InvalidPurchaseInboundAllocation::quantityNotPositive(),
        InvalidPurchaseInboundAllocation::quantityRequiredForSplit(),
        InvalidPurchaseInboundAllocation::inboundQuantityUnavailable($line),
        InvalidPurchaseInboundAllocation::unresolvedHistoricalQuantity($allocation),
        InvalidPurchaseInboundAllocation::duplicateWarehouse($warehouse),
        InvalidPurchaseInboundAllocation::overAllocated('1', '2'),
        InvalidPurchaseInboundAllocation::overSupplierCommitment('1', '2'),
        InvalidPurchaseInboundAllocation::supplierCommitmentUnavailable(),
        InvalidPurchaseInboundAllocation::supplierCommitmentBelowAllocated('1', '2'),
        InvalidPurchaseInboundAllocation::inactiveWarehouse($warehouse),
        InvalidPurchaseInboundAllocation::wrongInboundLine($allocation, $line),
        InvalidPurchaseInboundAllocation::belowCommitted('1'),
        InvalidPurchaseInboundAllocation::cannotMoveCommittedAllocation(),
        InvalidPurchaseInboundAllocation::cannotDeleteCommitted('1'),
    ];

    $exceptions[] = PurchaseOrderNotReceivable::status($order);
    $exceptions[] = PurchaseOrderNotReceivable::inactiveWarehouse($warehouse);
    $exceptions[] = InvalidConfirmationTarget::unsupportedType();
    $exceptions[] = InvalidConfirmationTarget::promisedBeforeOrdered(
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-02'),
    );

    expect($exceptions)->each->toBeInstanceOf(DomainException::class);
});

it('covers order status cast compatibility branches', function (): void {
    $cast = new OrderStatusCast;
    $model = new class extends Model {};

    expect($cast->get($model, 'status', OrderStatus::Draft, []))->toBe(OrderStatus::Draft)
        ->and($cast->get($model, 'status', 'ready', []))->toBe(OrderStatus::Released)
        ->and($cast->get($model, 'status', 'supplier_confirmed', []))->toBe(OrderStatus::Confirmed)
        ->and($cast->get($model, 'status', 'draft', []))->toBe(OrderStatus::Draft)
        ->and($cast->set($model, 'status', OrderStatus::Closed, []))->toBe('closed')
        ->and($cast->set($model, 'status', 'ready', []))->toBe('released')
        ->and($cast->set($model, 'status', 'supplier_rejected', []))->toBe('confirmed')
        ->and($cast->set($model, 'status', 'cancelled', []))->toBe('cancelled');

    expect(fn (): OrderStatus => $cast->get($model, 'status', 123, []))->toThrow(UnexpectedValueException::class)
        ->and(fn (): string => $cast->set($model, 'status', 123, []))->toThrow(UnexpectedValueException::class);
});

it('covers quantity formatting edge cases', function (): void {
    expect(QuantityFormatter::display(null))->toBe('0')
        ->and(QuantityFormatter::display('abc'))->toBe('0')
        ->and(QuantityFormatter::display(0))->toBe('0')
        ->and(QuantityFormatter::display(-0.0000001))->toBe('0')
        ->and(QuantityFormatter::display(1234.5))->toBe('1,234.5')
        ->and(QuantityFormatter::display('12.345600'))->toBe('12.3456');
});

it('copies and normalizes price provenance attributes', function (): void {
    $source = new OrderLine;
    $target = new OrderLine;

    $source->forceFill([
        'resolved_price_source' => ResolvedPriceSource::ManualOverride,
        'resolved_price_tier_id' => 11,
        'price_floor_override_id' => 12,
        'list_price_minor' => 1300,
        'floor_price_minor' => 900,
    ]);

    $target->copyPriceProvenanceFrom($source);
    $attributes = $target->priceProvenanceAttributes();

    expect($target->priceProvenanceCasts()['resolved_price_source'])->toBe(ResolvedPriceSource::class)
        ->and($attributes['resolved_price_source'])->toBe(ResolvedPriceSource::ManualOverride)
        ->and($attributes['resolved_price_tier_id'])->toBe(11)
        ->and($attributes['price_floor_override_id'])->toBe(12)
        ->and($attributes['list_price_minor'])->toBe(1300)
        ->and($attributes['floor_price_minor'])->toBe(900);
});
