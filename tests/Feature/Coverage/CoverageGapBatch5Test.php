<?php

declare(strict_types=1);

use App\Enums\InventoryCorrectionStatus;
use App\Enums\ReservationStatus;
use App\Models\InventoryCorrection;
use App\Models\InventoryCorrectionLine;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\PurchaseInboundAllocation;
use App\Models\TaxRecognitionEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('covers inventory correction terminal immutability deletion and status helpers', function (): void {
    $posted = InventoryCorrection::factory()->posted()->create();

    expect($posted->isPosted())->toBeTrue()
        ->and($posted->isDraft())->toBeFalse()
        ->and($posted->isCancelled())->toBeFalse();

    $posted->reason = 'attempted mutation';
    expect(fn () => $posted->save())
        ->toThrow(DomainException::class, 'immutable');

    $draft = InventoryCorrection::factory()->create();
    expect(fn () => $draft->delete())
        ->toThrow(DomainException::class, 'cannot be deleted');

    $raw = $draft->getAttributes();
    $raw['status'] = null;
    $draft->setRawAttributes($raw, true);
    $draft->status = InventoryCorrectionStatus::Draft;
    $draft->notes = 'covers non-string original status';

    expect($draft->save())->toBeTrue();
});

it('covers correction-line parent lifecycle guards', function (): void {
    $posted = InventoryCorrection::factory()->posted()->create();

    expect(fn () => InventoryCorrectionLine::factory()->for($posted, 'correction')->create())
        ->toThrow(DomainException::class, 'draft correction');

    $draft = InventoryCorrection::factory()->create();
    $line = InventoryCorrectionLine::factory()->for($draft, 'correction')->create();

    $draft->forceFill([
        'status' => InventoryCorrectionStatus::Posted,
        'posted_at' => now(),
    ])->save();

    $line->transaction_quantity = '2.000000';
    expect(fn () => $line->save())
        ->toThrow(DomainException::class, 'immutable');

    $line->refresh();
    expect(fn () => $line->delete())
        ->toThrow(DomainException::class, 'draft correction');
});

it('covers tax recognition append-only mutation and deletion guards', function (): void {
    $entry = TaxRecognitionEntry::factory()->create();

    $entry->tax_amount = '999.99';
    expect(fn () => $entry->save())
        ->toThrow(DomainException::class, 'append-only');

    $entry->refresh();
    expect(fn () => $entry->delete())
        ->toThrow(DomainException::class, 'append-only');
});

it('covers loaded delivery reservations through the relationship query path', function (): void {
    $order = Order::factory()->create();
    $delivery = InventoryOperation::factory()->delivery()->create();
    $order->deliveries()->save($delivery);

    InventoryReservation::factory()->create([
        'source_type' => 'inventory_operation',
        'source_id' => $delivery->getKey(),
        'status' => ReservationStatus::Expired,
    ]);

    $order->load('deliveries');

    expect($order->deliveries->first()?->relationLoaded('reservations'))->toBeFalse()
        ->and($order->hasLapsedReservations())->toBeTrue();
});

it('covers purchase inbound allocation null and zero-floor remaining quantities', function (): void {
    $allocationWithoutQuantity = PurchaseInboundAllocation::factory()->create([
        'allocated_base_quantity' => null,
    ]);

    expect($allocationWithoutQuantity->remainingBaseQuantity())->toBeNull();

    $allocation = PurchaseInboundAllocation::factory()->create([
        'allocated_base_quantity' => '1.000000',
    ]);
    $receipt = InventoryOperation::factory()->receipt()->done()->create();

    InventoryOperationLine::factory()->for($receipt, 'operation')->create([
        'purchase_inbound_allocation_id' => $allocation->getKey(),
        'base_quantity' => '2.000000',
    ]);

    expect($allocation->remainingBaseQuantity())->toBe('0.000000');
});
