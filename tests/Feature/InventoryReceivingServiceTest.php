<?php

declare(strict_types=1);

use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptItem;
use App\Models\InventoryStock;
use App\Models\PriceHistory;
use App\Models\ProductVariant;
use App\Models\User;
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
    $variant = ProductVariant::factory()->create(['track_serials' => true]);
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
    $variant = ProductVariant::factory()->create(['track_expiry' => true]);
    InventoryReceiptItem::factory()->for($receipt, 'receipt')->for($variant, 'productVariant')->create([
        'quantity' => 3,
        'expires_at' => now()->addMonth(),
        'lot_number' => 'LOT-01',
    ]);

    app(InventoryReceivingService::class)->confirm($receipt, $actor);

    expect(InventoryLot::query()->where('lot_number', 'LOT-01')->exists())->toBeTrue();
});
