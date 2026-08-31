<?php

declare(strict_types=1);

use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Inventory\ProductVariantUomService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('posts a manual receipt in the variant base UOM and preserves its transaction snapshot', function (): void {
    $piece = Unit::factory()->whole()->create([
        'code' => 'CANONICAL-PIECE',
        'name' => 'Canonical piece',
        'symbol' => 'CPC',
    ]);
    $box = Unit::factory()->whole()->create([
        'code' => 'CANONICAL-BOX',
        'name' => 'Canonical box',
        'symbol' => 'CBX',
    ]);
    $variant = ProductVariant::factory()->create();

    app(ProductVariantUomService::class)->sync($variant, [
        canonicalReceiptUomDefinition($piece, isBase: true, factor: '1'),
        canonicalReceiptUomDefinition($box, factor: '100'),
    ]);

    $warehouse = Warehouse::factory()->create();
    $actor = User::factory()->create();
    $operation = InventoryOperation::factory()->receipt()->create([
        'destination_warehouse_id' => $warehouse->getKey(),
    ]);
    $line = $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $box->getKey(),
        'quantity' => '5',
    ]);

    $service = app(InventoryOperationService::class);
    $service->markReady($operation, $actor);
    $service->complete($operation->refresh(), $actor);

    $stock = InventoryStock::query()
        ->where('product_variant_id', $variant->getKey())
        ->where('warehouse_id', $warehouse->getKey())
        ->sole();
    $movement = InventoryMovement::query()->where('source_line_id', $line->getKey())->sole();
    $postedLine = $line->fresh();

    expect($stock->on_hand_quantity)->toBe('500.000000')
        ->and($postedLine->transaction_quantity)->toBe('5.000000')
        ->and($postedLine->transaction_unit_id)->toBe($box->getKey())
        ->and($postedLine->conversion_factor_snapshot)->toBe('100.000000')
        ->and($postedLine->base_quantity)->toBe('500.000000')
        ->and($movement->quantity)->toBe('500.000000')
        ->and($movement->base_quantity_delta)->toBe('500.000000')
        ->and($movement->transaction_unit_id)->toBe($box->getKey());
});

it('reuses a lot with the normalized base quantity rather than its receipt UOM quantity', function (): void {
    $piece = Unit::factory()->whole()->create([
        'code' => 'LOT-PIECE',
        'name' => 'Lot piece',
        'symbol' => 'LPC',
    ]);
    $box = Unit::factory()->whole()->create([
        'code' => 'LOT-BOX',
        'name' => 'Lot box',
        'symbol' => 'LBX',
    ]);
    $variant = ProductVariant::factory()->expiryMaterial()->create();

    app(ProductVariantUomService::class)->sync($variant, [
        canonicalReceiptUomDefinition($piece, isBase: true, factor: '1'),
        canonicalReceiptUomDefinition($box, factor: '20'),
    ]);

    $warehouse = Warehouse::factory()->create();
    $actor = User::factory()->create();
    $service = app(InventoryOperationService::class);

    foreach (['2', '3'] as $quantity) {
        $operation = InventoryOperation::factory()->receipt()->create([
            'destination_warehouse_id' => $warehouse->getKey(),
        ]);
        $operation->lines()->create([
            'product_variant_id' => $variant->getKey(),
            'unit_id' => $box->getKey(),
            'quantity' => $quantity,
            'lot_number' => 'CANONICAL-LOT-01',
            'expires_at' => now()->addMonth(),
        ]);

        $service->markReady($operation, $actor);
        $service->complete($operation->refresh(), $actor);
    }

    $lot = InventoryLot::query()->where('lot_number', 'CANONICAL-LOT-01')->sole();

    expect($lot->on_hand_quantity)->toBe('100.000000')
        ->and(InventoryStock::query()->where('product_variant_id', $variant->getKey())->sole()->on_hand_quantity)->toBe('100.000000');
});

/** @return array<string, bool|int|string> */
function canonicalReceiptUomDefinition(Unit $unit, bool $isBase = false, string $factor = '1'): array
{
    return [
        'unit_id' => $unit->getKey(),
        'is_base' => $isBase,
        'is_purchase' => true,
        'is_sale' => true,
        'is_display' => $isBase,
        'factor_to_base' => $factor,
        'rounding_increment' => '1',
        'permits_cross_family_conversion' => false,
        'is_active' => true,
    ];
}
