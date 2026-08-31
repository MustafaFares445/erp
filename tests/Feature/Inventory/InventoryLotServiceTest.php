<?php

declare(strict_types=1);

use App\Models\InventoryLot;
use App\Models\InventoryOperationLine;
use App\Models\ProductVariant;
use App\Services\Inventory\InventoryLotService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// These call InventoryLotService directly rather than through InventoryOperationService, because
// every reachable path through the operation lifecycle validates the same conditions earlier
// (ProductTypeGuard on markReady(), the line's own inventory_lot_id checks) — these guards inside
// the lot service itself are the defensive second line that a direct caller can still hit.

it('refuses to record a lot for a receiving line with no expiry date', function (): void {
    $variant = ProductVariant::factory()->expiryMaterial()->create();
    $line = InventoryOperationLine::factory()->make([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '5.000',
        'expires_at' => null,
    ]);

    expect(fn (): ?InventoryLot => app(InventoryLotService::class)->receive($line, $variant, 1))
        ->toThrow(DomainException::class, __('admin.inventory.product_type.errors.expiry_required'));
});

it('refuses to consume a lot for a variant that does not track batches', function (): void {
    $variant = ProductVariant::factory()->machine()->create();
    $line = InventoryOperationLine::factory()->make([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1.000',
        'inventory_lot_id' => 999999,
    ]);

    expect(fn (): ?InventoryLot => app(InventoryLotService::class)->consume($line, $variant, 1, null))
        ->toThrow(DomainException::class, __('admin.inventory.lot.errors.not_applicable'));
});

it('does nothing when consuming a line for a variant that does not track batches and names no lot', function (): void {
    $variant = ProductVariant::factory()->machine()->create();
    $line = InventoryOperationLine::factory()->make([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1.000',
        'inventory_lot_id' => null,
    ]);

    expect(app(InventoryLotService::class)->consume($line, $variant, 1, null))->toBeNull();
});

it('refuses to consume a lot id that no longer exists', function (): void {
    $variant = ProductVariant::factory()->grain()->create();
    $line = InventoryOperationLine::factory()->make([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1.000',
        'inventory_lot_id' => 999999,
    ]);

    expect(fn (): ?InventoryLot => app(InventoryLotService::class)->consume($line, $variant, 1, null))
        ->toThrow(DomainException::class, __('admin.inventory.lot.errors.required'));
});

it('refuses to consume a lot that no longer holds enough available quantity', function (): void {
    $variant = ProductVariant::factory()->grain()->create();
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->create([
        'on_hand_quantity' => '2.000',
        'reserved_quantity' => '2.000',
        'expires_at' => null,
    ]);
    $line = InventoryOperationLine::factory()->make([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '5.000',
        'inventory_lot_id' => $lot->getKey(),
    ]);

    expect(fn (): ?InventoryLot => app(InventoryLotService::class)->consume($line, $variant, $lot->warehouse_id, null))
        ->toThrow(DomainException::class, __('admin.inventory.lot.errors.insufficient_quantity', ['lot' => $lot->lot_number]));
});

it('returns null restoring a lot that no longer exists', function (): void {
    $variant = ProductVariant::factory()->grain()->create();
    $line = InventoryOperationLine::factory()->make([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1.000',
        'inventory_lot_id' => 999999,
    ]);

    expect(app(InventoryLotService::class)->restore($line, $variant))->toBeNull();
});


it('resolves inbound lot identity without changing its quantity', function (): void {
    $variant = ProductVariant::factory()->grain()->create();
    $line = InventoryOperationLine::factory()->make([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '5.000000',
        'lot_number' => 'IDENTITY-ONLY',
    ]);

    $lot = app(InventoryLotService::class)->receive($line, $variant, 1, '5.000000');

    expect($lot)->not->toBeNull()
        ->and($lot?->on_hand_quantity)->toBe('0.000000')
        ->and($lot?->reserved_quantity)->toBe('0.000000');
});
