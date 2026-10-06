<?php

declare(strict_types=1);

use App\Enums\PurchaseAgreementStatus;
use App\Enums\PurchaseRfqStatus;
use App\Models\ProductVariant;
use App\Models\ProductVariantUnit;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\Unit;
use App\Models\User;
use App\Services\Purchasing\PurchaseAgreementService;
use App\Services\Purchasing\PurchaseRfqService;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
    (new CurrencySeeder)->run();
});

function coverage89Catalog(bool $withReference = true, bool $purchaseUnit = true): array
{
    $supplier = Supplier::factory()->create(['is_active' => true]);
    $variant = ProductVariant::factory()->create(['is_active' => true]);
    $unit = Unit::factory()->create();

    ProductVariantUnit::factory()->create([
        'product_variant_id' => $variant->id,
        'unit_id' => $unit->id,
        'is_purchase' => $purchaseUnit,
        'is_active' => true,
        'factor_to_base' => '1.000000',
    ]);

    if ($withReference) {
        SupplierProductReference::factory()->create([
            'supplier_id' => $supplier->id,
            'product_variant_id' => $variant->id,
            'purchase_cost' => '10.00',
            'currency_code' => 'AED',
            'availability_status' => 'active',
            'is_active' => true,
        ]);
    }

    return [$supplier, $variant, $unit];
}

it('covers purchase agreement creation validation guards', function (): void {
    $service = app(PurchaseAgreementService::class);
    $actor = User::factory()->create();
    [$supplier, $variant, $unit] = coverage89Catalog();

    expect(fn () => $service->create($actor, [
        'supplier_id' => $supplier->id,
        'currency_code' => 'AED',
        'starts_on' => today()->toDateString(),
    ], []))->toThrow(DomainException::class, 'at least one line');

    $inactive = Supplier::factory()->create(['is_active' => false]);
    expect(fn () => $service->create($actor, [
        'supplier_id' => $inactive->id,
        'currency_code' => 'AED',
        'starts_on' => today()->toDateString(),
    ], [[
        'product_variant_id' => $variant->id,
        'unit_id' => $unit->id,
        'unit_price' => 10,
    ]]))->toThrow(DomainException::class, 'inactive supplier');

    expect(fn () => $service->create($actor, [
        'supplier_id' => $supplier->id,
        'currency_code' => 'AED',
        'starts_on' => today()->toDateString(),
        'ends_on' => today()->subDay()->toDateString(),
    ], [[
        'product_variant_id' => $variant->id,
        'unit_id' => $unit->id,
        'unit_price' => 10,
    ]]))->toThrow(DomainException::class, 'end date cannot be before');

    foreach ([
        ['unit_price' => -1, 'minimum_order_quantity' => null, 'lead_time_days' => null, 'message' => 'unit price cannot be negative'],
        ['unit_price' => 1, 'minimum_order_quantity' => 0, 'lead_time_days' => null, 'message' => 'minimum order quantity must be positive'],
        ['unit_price' => 1, 'minimum_order_quantity' => 1, 'lead_time_days' => -1, 'message' => 'lead time cannot be negative'],
    ] as $case) {
        expect(fn () => $service->create($actor, [
            'supplier_id' => $supplier->id,
            'currency_code' => 'AED',
            'starts_on' => today()->toDateString(),
        ], [[
            'product_variant_id' => $variant->id,
            'unit_id' => $unit->id,
            'unit_price' => $case['unit_price'],
            'minimum_order_quantity' => $case['minimum_order_quantity'],
            'lead_time_days' => $case['lead_time_days'],
        ]]))->toThrow(DomainException::class, $case['message']);
    }

    expect(fn () => $service->create($actor, [
        'supplier_id' => $supplier->id,
        'currency_code' => 'AED',
        'starts_on' => today()->toDateString(),
    ], [
        ['product_variant_id' => $variant->id, 'unit_id' => $unit->id, 'unit_price' => 1],
        ['product_variant_id' => $variant->id, 'unit_id' => $unit->id, 'unit_price' => 2],
    ]))->toThrow(DomainException::class, 'may appear only once');

    [$supplierNoRef, $variantNoRef, $unitNoRef] = coverage89Catalog(false);
    expect(fn () => $service->create($actor, [
        'supplier_id' => $supplierNoRef->id,
        'currency_code' => 'AED',
        'starts_on' => today()->toDateString(),
    ], [[
        'product_variant_id' => $variantNoRef->id,
        'unit_id' => $unitNoRef->id,
        'unit_price' => 1,
    ]]))->toThrow(DomainException::class, 'active supplier product reference');

    [$supplierBadUnit, $variantBadUnit, $unitBadUnit] = coverage89Catalog(true, false);
    expect(fn () => $service->create($actor, [
        'supplier_id' => $supplierBadUnit->id,
        'currency_code' => 'AED',
        'starts_on' => today()->toDateString(),
    ], [[
        'product_variant_id' => $variantBadUnit->id,
        'unit_id' => $unitBadUnit->id,
        'unit_price' => 1,
    ]]))->toThrow(DomainException::class, 'active purchase unit');
});

it('covers agreement activation cancellation and expiration guards', function (): void {
    $service = app(PurchaseAgreementService::class);
    $actor = User::factory()->create();
    [$supplier, $variant, $unit] = coverage89Catalog();

    $line = [[
        'product_variant_id' => $variant->id,
        'unit_id' => $unit->id,
        'unit_price' => 10,
    ]];

    $agreement = $service->create($actor, [
        'supplier_id' => $supplier->id,
        'currency_code' => 'AED',
        'starts_on' => today()->toDateString(),
    ], $line);

    $active = $service->activate($actor, $agreement);
    expect(fn () => $service->activate($actor, $active))
        ->toThrow(DomainException::class, 'Only a draft');

    $empty = $service->create($actor, [
        'supplier_id' => $supplier->id,
        'currency_code' => 'AED',
        'starts_on' => today()->toDateString(),
    ], $line);
    $empty->lines()->delete();
    expect(fn () => $service->activate($actor, $empty->refresh()))
        ->toThrow(DomainException::class, 'empty purchase agreement');

    $past = $service->create($actor, [
        'supplier_id' => $supplier->id,
        'currency_code' => 'AED',
        'starts_on' => today()->subDays(3)->toDateString(),
        'ends_on' => today()->subDay()->toDateString(),
    ], $line);
    expect(fn () => $service->activate($actor, $past))
        ->toThrow(DomainException::class, 'already ended');

    $draftForExpire = $service->create($actor, [
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'starts_on' => today()->toDateString(),
    ], $line);
    expect(fn () => $service->expire($actor, $draftForExpire))
        ->toThrow(DomainException::class, 'Only an active');

    $noEnd = $service->activate($actor, $service->create($actor, [
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'starts_on' => today()->toDateString(),
    ], $line));
    expect(fn () => $service->expire($actor, $noEnd))
        ->toThrow(DomainException::class, 'not reached its end date');

    $active->forceFill(['ends_on' => today()->subDay()])->save();
    expect($service->expire($actor, $active->refresh())->status)->toBe(PurchaseAgreementStatus::Expired)
        ->and(fn () => $service->cancel($actor, $active->refresh()))
        ->toThrow(DomainException::class, 'expired purchase agreement');

    $cancelled = $service->cancel($actor, $noEnd);
    expect($service->cancel($actor, $cancelled)->status)->toBe(PurchaseAgreementStatus::Cancelled);
});

it('covers RFQ creation send response and expiration guard branches', function (): void {
    $service = app(PurchaseRfqService::class);
    $actor = User::factory()->create();
    [$supplier, $variant, $unit] = coverage89Catalog();

    expect(fn () => $service->create($actor, ['currency_code' => 'AED'], [], [$supplier->id]))
        ->toThrow(DomainException::class, 'at least one line and one supplier');

    expect(fn () => $service->create($actor, ['currency_code' => 'AED'], [[
        'product_variant_id' => $variant->id,
        'unit_id' => $unit->id,
        'quantity' => 0,
    ]], [$supplier->id]))->toThrow(DomainException::class, 'quantity must be positive');

    $inactive = Supplier::factory()->create(['is_active' => false]);
    expect(fn () => $service->create($actor, ['currency_code' => 'AED'], [[
        'product_variant_id' => $variant->id,
        'unit_id' => $unit->id,
        'quantity' => 1,
    ]], [$inactive->id]))->toThrow(DomainException::class, 'inactive');

    $past = $service->create($actor, [
        'currency_code' => 'AED',
        'closes_at' => now()->subMinute()->toDateTimeString(),
    ], [[
        'product_variant_id' => $variant->id,
        'unit_id' => $unit->id,
        'quantity' => 1,
    ]], [$supplier->id]);
    expect(fn () => $service->send($actor, $past))
        ->toThrow(DomainException::class, 'closing time has passed');

    $rfq = $service->create($actor, [
        'currency_code' => 'AED',
        'closes_at' => now()->addDay()->toDateTimeString(),
    ], [[
        'product_variant_id' => $variant->id,
        'unit_id' => $unit->id,
        'quantity' => 2,
    ]], [$supplier->id]);

    $sent = $service->send($actor, $rfq);
    expect(fn () => $service->send($actor, $sent))
        ->toThrow(DomainException::class, 'Only a draft RFQ');

    $candidate = $sent->suppliers()->firstOrFail();
    $line = $sent->lines()->firstOrFail();

    expect(fn () => $service->recordResponse($actor, $candidate, []))
        ->toThrow(DomainException::class, 'At least one response line');

    foreach ([
        ['unit_price' => -1, 'offered_quantity' => 1],
        ['unit_price' => 1, 'offered_quantity' => 0],
    ] as $response) {
        expect(fn () => $service->recordResponse($actor, $candidate, [[
            'rfq_line_id' => $line->id,
            ...$response,
        ]]))->toThrow(DomainException::class, 'price and quantity are invalid');
    }

    $sent->forceFill(['closes_at' => now()->subMinute()])->save();
    expect(fn () => $service->recordResponse($actor, $candidate, [[
        'rfq_line_id' => $line->id,
        'unit_price' => 1,
        'offered_quantity' => 1,
    ]]))->toThrow(DomainException::class, 'past its closing time');

    $future = $service->create($actor, [
        'currency_code' => 'AED',
        'closes_at' => now()->addDay()->toDateTimeString(),
    ], [[
        'product_variant_id' => $variant->id,
        'unit_id' => $unit->id,
        'quantity' => 1,
    ]], [$supplier->id]);
    expect(fn () => $service->expire($actor, $future))
        ->toThrow(DomainException::class, 'past its closing time');

    $sent->forceFill(['closes_at' => now()->subMinute()])->save();
    expect($service->expire($actor, $sent->refresh())->status)->toBe(PurchaseRfqStatus::Expired)
        ->and(fn () => $service->expire($actor, $sent->refresh()))
        ->toThrow(DomainException::class, 'cannot be expired');
});

it('moves an RFQ through partially responded before all suppliers have replied', function (): void {
    $service = app(PurchaseRfqService::class);
    $actor = User::factory()->create();
    [$supplier, $variant, $unit] = coverage89Catalog();
    $second = Supplier::factory()->create(['is_active' => true]);

    $rfq = $service->create($actor, [
        'currency_code' => 'AED',
        'closes_at' => now()->addDay()->toDateTimeString(),
    ], [[
        'product_variant_id' => $variant->id,
        'unit_id' => $unit->id,
        'quantity' => 2,
    ]], [$supplier->id, $second->id]);

    $service->send($actor, $rfq);
    $candidate = $rfq->refresh()->suppliers()->where('supplier_id', $supplier->id)->firstOrFail();
    $line = $rfq->lines()->firstOrFail();

    $service->recordResponse($actor, $candidate, [[
        'rfq_line_id' => $line->id,
        'unit_price' => 10,
        'offered_quantity' => 2,
        'minimum_order_quantity' => 1,
        'notes' => 'First supplier response',
    ]]);

    expect($rfq->refresh()->status)->toBe(PurchaseRfqStatus::PartiallyResponded);
});
