<?php

declare(strict_types=1);

use App\Enums\ProductType;
use App\Enums\ReceiptStatus;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptItem;
use App\Models\InventoryStock;
use App\Models\PriceHistory;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryReceivingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('confirms a receipt atomically and records stock, movement, and costing history', function (): void {
    $actor = User::factory()->create();
    $receipt = InventoryReceipt::factory()->create();
    $variant = ProductVariant::factory()->create(['markup_percent' => 25]);
    InventoryReceiptItem::factory()->for($receipt, 'receipt')->for($variant, 'productVariant')->create([
        'quantity' => 4,
        'purchase_cost' => 10,
    ]);

    app(InventoryReceivingService::class)->confirm($receipt, $actor);

    expect($receipt->fresh()->status->value)->toBe('confirmed')
        ->and((float) InventoryStock::query()->where('warehouse_id', $receipt->warehouse_id)->value('on_hand_quantity'))->toBe(4.0)
        ->and(InventoryMovement::query()->where('movement_type', 'receipt')->count())->toBe(1)
        ->and((float) $variant->fresh()->base_price)->toBe(12.5)
        ->and(PriceHistory::query()->count())->toBe(1);
});

it('rolls back a serialized receipt with missing device identities', function (): void {
    $actor = User::factory()->create();
    $receipt = InventoryReceipt::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    InventoryReceiptItem::factory()->for($receipt, 'receipt')->for($variant, 'productVariant')->create(['quantity' => 2]);

    expect(fn (): mixed => app(InventoryReceivingService::class)->confirm($receipt, $actor))
        ->toThrow(DomainException::class);

    expect($receipt->fresh()->status->value)->toBe('draft')
        ->and(InventoryStock::query()->count())->toBe(0)
        ->and(InventoryMovement::query()->count())->toBe(0);
});

it('creates a lot for expiry-tracked receipt items', function (): void {
    $actor = User::factory()->create();
    $receipt = InventoryReceipt::factory()->create();
    $variant = ProductVariant::factory()->expiryMaterial()->create();
    InventoryReceiptItem::factory()->for($receipt, 'receipt')->for($variant, 'productVariant')->create([
        'quantity' => 3,
        'expires_at' => now()->addMonth(),
        'lot_number' => 'LOT-01',
    ]);

    app(InventoryReceivingService::class)->confirm($receipt, $actor);

    expect(InventoryLot::query()->where('lot_number', 'LOT-01')->exists())->toBeTrue();
});

it('rejects receipts that are no longer drafts or have no usable warehouse or lines', function (): void {
    $actor = User::factory()->create();
    $service = app(InventoryReceivingService::class);
    $confirmed = InventoryReceipt::factory()->create();
    $confirmed->forceFill(['status' => ReceiptStatus::Confirmed])->save();

    expect(fn () => $service->confirm($confirmed, $actor))
        ->toThrow(DomainException::class, __('admin.inventory.receipt.errors.not_draft'));

    $inactiveWarehouse = Warehouse::factory()->inactive()->create();
    $inactiveReceipt = InventoryReceipt::factory()->create([
        'warehouse_id' => $inactiveWarehouse->getKey(),
    ]);

    expect(fn () => $service->confirm($inactiveReceipt, $actor))
        ->toThrow(DomainException::class, __('admin.inventory.receipt.errors.inactive_warehouse'));

    $empty = InventoryReceipt::factory()->create();

    expect(fn () => $service->confirm($empty, $actor))
        ->toThrow(DomainException::class, __('admin.inventory.receipt.errors.no_items'));
});

it('rejects inactive variants and invalid receipt quantities', function (): void {
    $actor = User::factory()->create();
    $service = app(InventoryReceivingService::class);
    $inactiveVariant = ProductVariant::factory()->create(['is_active' => false]);
    $inactiveReceipt = InventoryReceipt::factory()->create();
    InventoryReceiptItem::factory()->for($inactiveReceipt, 'receipt')->for($inactiveVariant, 'productVariant')->create();

    expect(fn () => $service->confirm($inactiveReceipt, $actor))
        ->toThrow(DomainException::class, __('admin.inventory.receipt.errors.inactive_variant'));

    $zeroReceipt = InventoryReceipt::factory()->create();
    InventoryReceiptItem::factory()->for($zeroReceipt, 'receipt')->create(['quantity' => 0]);

    expect(fn () => $service->confirm($zeroReceipt, $actor))
        ->toThrow(DomainException::class, __('admin.inventory.receipt.errors.invalid_quantity'));

    $unit = Unit::query()->create([
        'name' => 'Piece',
        'symbol' => 'PC',
        'allows_decimal' => false,
        'is_active' => true,
    ]);
    $wholeUnitReceipt = InventoryReceipt::factory()->create();
    InventoryReceiptItem::factory()->for($wholeUnitReceipt, 'receipt')->create([
        'unit_id' => $unit->getKey(),
        'quantity' => 1.5,
    ]);

    expect(fn () => $service->confirm($wholeUnitReceipt, $actor))
        ->toThrow(DomainException::class, __('admin.inventory.receipt.errors.invalid_unit_quantity'));
});

it('rejects fractional missing and mismatched serialized receipt identities', function (): void {
    $actor = User::factory()->create();
    $service = app(InventoryReceivingService::class);
    $serializedVariant = ProductVariant::factory()->machine()->create();
    $fractionalReceipt = InventoryReceipt::factory()->create();
    InventoryReceiptItem::factory()
        ->for($fractionalReceipt, 'receipt')
        ->for($serializedVariant, 'productVariant')
        ->create(['quantity' => 1.5]);

    // The product type owns this rule now: a machine is never fractional, whatever its unit
    // permits and whether or not serials happen to be attached to the line.
    expect(fn () => $service->confirm($fractionalReceipt, $actor))
        ->toThrow(DomainException::class, __('admin.inventory.product_type.errors.whole_quantity_required', [
            'type' => ProductType::Machine->label(),
        ]));

    $mismatchedReceipt = InventoryReceipt::factory()->create();
    $mismatchedItem = InventoryReceiptItem::factory()
        ->for($mismatchedReceipt, 'receipt')
        ->for($serializedVariant, 'productVariant')
        ->create(['quantity' => 1]);
    SerializedInventoryUnit::factory()->create([
        'inventory_receipt_item_id' => $mismatchedItem->getKey(),
        'product_variant_id' => ProductVariant::factory(),
    ]);

    expect(fn () => $service->confirm($mismatchedReceipt, $actor))
        ->toThrow(DomainException::class, __('admin.inventory.receipt.errors.serial_variant_mismatch'));
});

it('requires expiry dates for expiry-tracked receipt lines', function (): void {
    $actor = User::factory()->create();
    $receipt = InventoryReceipt::factory()->create();
    $variant = ProductVariant::factory()->expiryMaterial()->create();
    InventoryReceiptItem::factory()
        ->for($receipt, 'receipt')
        ->for($variant, 'productVariant')
        ->create(['expires_at' => null]);

    expect(fn () => app(InventoryReceivingService::class)->confirm($receipt, $actor))
        ->toThrow(DomainException::class, __('admin.inventory.receipt.errors.expiry_required'));
});
