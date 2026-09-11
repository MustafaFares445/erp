<?php

declare(strict_types=1);

use App\Enums\MovementType;
use App\Enums\StockCondition;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\Inventory\StockAvailabilityExplainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('keeps stock availability explanation query count constant as quarantine receipt volume grows', function (): void {
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();

    InventoryStock::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '8.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '0.000000',
    ]);

    foreach ([
        [StockCondition::Saleable, '0.000000'],
        [StockCondition::Quarantine, '8.000000'],
        [StockCondition::Damaged, '0.000000'],
    ] as [$condition, $onHand]) {
        InventoryConditionBalance::query()->forceCreate([
            'product_variant_id' => $variant->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'stock_condition' => $condition->value,
            'on_hand_base_quantity' => $onHand,
            'reserved_base_quantity' => '0.000000',
        ]);
    }

    $createReceiptMovement = function () use ($variant, $warehouse): void {
        $operation = InventoryOperation::factory()->receipt()->done()->create([
            'destination_warehouse_id' => $warehouse->getKey(),
        ]);

        InventoryMovement::query()->forceCreate([
            'product_variant_id' => $variant->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'movement_type' => MovementType::Receipt->value,
            'quantity' => '1.000',
            'source_type' => 'inventory_operation',
            'source_id' => $operation->getKey(),
            'transaction_quantity' => '1.000000',
            'transaction_unit_id' => $variant->unit_id,
            'conversion_factor_snapshot' => '1.000000',
            'base_quantity_delta' => '1.000000',
            'stock_condition_to' => StockCondition::Quarantine->value,
            'status' => 'confirmed',
        ]);
    };

    $measureQueries = function () use ($variant, $warehouse): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            app(StockAvailabilityExplainer::class)->explain($variant, $warehouse);

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    };

    $createReceiptMovement();
    $singleReceiptQueries = $measureQueries();

    foreach (range(2, 8) as $_) {
        $createReceiptMovement();
    }

    $manyReceiptQueries = $measureQueries();

    expect($singleReceiptQueries)->toBeLessThanOrEqual(10)
        ->and($manyReceiptQueries)->toBe($singleReceiptQueries);
});
