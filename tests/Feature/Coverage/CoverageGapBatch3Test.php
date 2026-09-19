<?php

declare(strict_types=1);

use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\QuotationLine;
use App\Models\Unit;
use App\Services\Purchasing\Exceptions\InvalidConfirmationTarget;
use App\Services\Purchasing\SupplierConfirmationService;
use App\Services\Sales\QuotationConversionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('covers quotation conversion snapshot normalization and numeric guards', function (): void {
    $service = app(QuotationConversionService::class);
    $numeric = new ReflectionMethod(QuotationConversionService::class, 'numericString');
    expect($numeric->invoke($service, 12.5))->toBe('12.5');
    expect(fn (): mixed => $numeric->invoke($service, []))
        ->toThrow(LogicException::class, 'Quotation quantity snapshots must be numeric.');

    $snapshot = new ReflectionMethod(QuotationConversionService::class, 'snapshotFor');
    expect(fn (): mixed => $snapshot->invoke($service, new QuotationLine))
        ->toThrow(LogicException::class, 'A quotation line requires a product variant.');

    $variant = ProductVariant::factory()->create();
    $line = QuotationLine::factory()->for($variant, 'productVariant')->create();
    $line->forceFill([
        'transaction_quantity' => null,
        'transaction_unit_id' => null,
        'conversion_factor_snapshot' => null,
        'base_quantity' => null,
    ])->saveQuietly();
    $normalized = $snapshot->invoke($service, $line->refresh());
    expect($normalized->transactionUnitId)->toBe($variant->unit_id)
        ->and($line->refresh()->conversion_factor_snapshot)->not->toBeNull();

    $existing = QuotationLine::factory()->for($variant, 'productVariant')->create();
    $existingSnapshot = $snapshot->invoke($service, $existing->refresh());
    expect($existingSnapshot->baseUnitId)->toBe($variant->unit_id);
});

it('covers supplier confirmation quantity and UOM helper branches', function (): void {
    $service = app(SupplierConfirmationService::class);
    $normalize = new ReflectionMethod(SupplierConfirmationService::class, 'normalizeQuantity');

    expect($normalize->invoke($service, '2.5', 'quantity'))->toBe('2.500000');
    expect(fn (): mixed => $normalize->invoke($service, [], 'quantity'))
        ->toThrow(ValidationException::class);
    expect(fn (): mixed => $normalize->invoke($service, -1, 'quantity'))
        ->toThrow(ValidationException::class);

    $variant = ProductVariant::factory()->create();
    $sameUnitLine = PurchaseOrderLine::factory()->for($variant, 'productVariant')->create([
        'unit_id' => $variant->unit_id,
    ]);
    $requested = new ReflectionMethod(SupplierConfirmationService::class, 'requestedTransactionQuantity');
    expect($requested->invoke($service, $sameUnitLine, '3.000000'))->toBe('3.000');

    $differentUnit = Unit::factory()->create();
    $missingSnapshotLine = PurchaseOrderLine::factory()->for($variant, 'productVariant')->create([
        'unit_id' => $differentUnit->getKey(),
    ]);
    expect(fn (): mixed => $requested->invoke($service, $missingSnapshotLine, '3.000000'))
        ->toThrow(ValidationException::class);

    $order = PurchaseOrder::factory()->create(['ordered_at' => '2026-09-10']);
    $assertPromised = new ReflectionMethod(SupplierConfirmationService::class, 'assertPromisedDate');
    expect(fn (): mixed => $assertPromised->invoke($service, $order, CarbonImmutable::parse('2026-09-09')))
        ->toThrow(InvalidConfirmationTarget::class);

    $relations = new ReflectionMethod(SupplierConfirmationService::class, 'relations');
    expect($relations->invoke($service))->toContain('purchaseOrder', 'supplier', 'items.confirmedBy');
});
