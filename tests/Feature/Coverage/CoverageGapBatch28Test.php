<?php

declare(strict_types=1);

use App\Enums\PurchaseInboundStatus;
use App\Filament\Pages\LogisticsOutboundQueue;
use App\Models\ProductVariant;
use App\Models\PurchaseInbound;
use App\Services\Inventory\LogisticsInboundProjectionService;
use App\Services\Logistics\OutboundAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('covers remaining inbound projection business-state action and numeric guards', function (): void {
    $service = new ReflectionClass(LogisticsInboundProjectionService::class)->newInstanceWithoutConstructor();

    $businessState = new ReflectionMethod(LogisticsInboundProjectionService::class, 'businessState');
    $nextAction = new ReflectionMethod(LogisticsInboundProjectionService::class, 'nextAction');
    $decimal = new ReflectionMethod(LogisticsInboundProjectionService::class, 'decimal');
    $numericString = new ReflectionMethod(LogisticsInboundProjectionService::class, 'numericString');

    $cancelled = new PurchaseInbound;
    $cancelled->forceFill(['status' => PurchaseInboundStatus::Cancelled]);

    $awaiting = new PurchaseInbound;
    $awaiting->forceFill(['status' => PurchaseInboundStatus::AwaitingReceipt]);

    expect($businessState->invoke($service, $cancelled, []))->toBe('Cancelled / Closed')
        ->and($businessState->invoke($service, $awaiting, []))->toBe('Awaiting Allocation')
        ->and($nextAction->invoke($service, 'Awaiting Allocation'))->toBe('Allocate warehouse quantities')
        ->and(fn (): mixed => $decimal->invoke($service, 'not-a-number'))
        ->toThrow(LogicException::class, 'An inbound quantity must be numeric.')
        ->and(fn (): mixed => $numericString->invoke($service, 'not-a-number'))
        ->toThrow(LogicException::class, 'An inbound quantity must be numeric.');
});

it('covers untracked outbound assignment and numeric-key guard', function (): void {
    $service = app(OutboundAvailabilityService::class);
    $variant = new ProductVariant;
    $variant->forceFill([
        'id' => 123,
        'track_serials' => false,
        'track_batches' => false,
    ]);

    $assignments = new ReflectionMethod(OutboundAvailabilityService::class, 'trackedAssignments');
    $integerKey = new ReflectionMethod(OutboundAvailabilityService::class, 'integerKey');

    expect($assignments->invoke($service, $variant, 1, 2.5))->toBe([[
        'product_variant_id' => $variant->getKey(),
        'quantity' => 2.5,
        'inventory_lot_id' => null,
        'serialized_inventory_unit_ids' => [],
    ]]);

    expect(fn (): mixed => $integerKey->invoke($service, 'not-numeric'))
        ->toThrow(LogicException::class, 'An inventory record must have a numeric identifier.');
});

it('covers outbound queue title and navigation label', function (): void {
    expect(app(LogisticsOutboundQueue::class)->getTitle())
        ->toBe(__('admin.resources.outbound_fulfillment'))
        ->and(LogisticsOutboundQueue::getNavigationLabel())
        ->toBe(__('admin.resources.outbound_fulfillment'));
});
