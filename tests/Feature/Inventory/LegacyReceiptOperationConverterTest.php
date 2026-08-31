<?php

declare(strict_types=1);

use App\Enums\OperationStage;
use App\Enums\SerializedInventoryUnitStatus;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Services\Inventory\LegacyReceiptOperationConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('converts a retained legacy receipt through one canonical receipt operation without double posting', function (): void {
    $actor = User::factory()->create();
    $receipt = InventoryReceipt::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $item = $receipt->items()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity' => '1',
        'purchase_cost' => '2500.00',
    ]);
    $serializedUnit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'inventory_receipt_item_id' => $item->getKey(),
        'warehouse_id' => null,
        'status' => SerializedInventoryUnitStatus::Pending,
    ]);

    $converter = app(LegacyReceiptOperationConverter::class);
    $operation = $converter->complete($receipt, $actor);
    $retry = $converter->complete($receipt, $actor);

    expect($operation->stage)->toBe(OperationStage::Done)
        ->and($retry->getKey())->toBe($operation->getKey())
        ->and(InventoryMovement::query()->where('source_id', $operation->getKey())->count())->toBe(1)
        ->and(InventoryStock::query()->where('warehouse_id', $receipt->warehouse_id)->sole()->on_hand_quantity)->toBe('1.000000')
        ->and($serializedUnit->fresh()->status)->toBe(SerializedInventoryUnitStatus::Available)
        ->and($serializedUnit->fresh()->warehouse_id)->toBe($receipt->warehouse_id);
});
