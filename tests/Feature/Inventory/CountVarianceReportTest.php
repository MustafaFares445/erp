<?php

declare(strict_types=1);

use App\Data\Inventory\CountScopeData;
use App\Enums\CountScope;
use App\Enums\InventoryExportType;
use App\Enums\InventoryPermission;
use App\Enums\InventoryReportType;
use App\Enums\StockCondition;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryCountService;
use App\Services\Inventory\InventoryReportFormatter;
use App\Services\Inventory\InventoryReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function countReportActor(): User
{
    $actor = User::factory()->create();

    foreach ([
        InventoryPermission::ReportView,
        InventoryPermission::CountView,
        InventoryPermission::CountOpen,
        InventoryPermission::CountRecord,
        InventoryPermission::CountConfirm,
    ] as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission->value, 'web'));
    }

    return $actor;
}

/**
 * WP-3.5 (GAP-MW-06) — the physical count variance report reads only
 * counted lines from a CONFIRMED count, never a draft's numbers.
 */
it('reports only counted lines from a confirmed count, scoped by warehouse', function (): void {
    $actor = countReportActor();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();

    InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '10.000000', 'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000', 'available_quantity' => '10.000000',
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '10.000000', 'reserved_quantity' => '0.000000',
    ]);

    foreach ([StockCondition::Saleable, StockCondition::Quarantine, StockCondition::Damaged] as $condition) {
        $quantity = $condition === StockCondition::Saleable ? '10.000000' : '0.000000';

        InventoryConditionBalance::query()->forceCreate([
            'product_variant_id' => $variant->getKey(), 'warehouse_id' => $warehouse->getKey(),
            'stock_condition' => $condition,
            'on_hand_base_quantity' => $quantity, 'reserved_base_quantity' => '0.000000',
        ]);

        InventoryLotBalance::query()->firstOrNew([
            'inventory_lot_id' => $lot->getKey(), 'warehouse_id' => $warehouse->getKey(),
            'stock_condition' => $condition->value,
        ])->forceFill([
            'on_hand_base_quantity' => $quantity, 'reserved_base_quantity' => '0.000000',
        ])->save();
    }

    $countService = app(InventoryCountService::class);
    $counter = User::factory()->create();
    $confirmer = User::factory()->create();

    $count = $countService->open(new CountScopeData(
        warehouseId: $warehouse->getKey(),
        scopeType: CountScope::Warehouse,
        productCategoryId: null,
        inventoryLotId: null,
        productVariantIds: null,
        conditions: [StockCondition::Saleable->value],
        materialityThresholdMinor: null,
    ), $counter);

    $line = $count->lines()->sole();
    $countService->recordCount($line, '8.000000', $counter);
    $submitted = $countService->submitForReview($count, $counter);
    $countService->confirm($submitted, $confirmer);

    $reportService = app(InventoryReportService::class);
    $rows = $reportService->query(InventoryReportType::CountVariance, [
        'warehouse_id' => $warehouse->getKey(),
    ])->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()?->getKey())->toBe($line->getKey());

    $otherWarehouse = Warehouse::factory()->create();
    $scopedOut = $reportService->query(InventoryReportType::CountVariance, [
        'warehouse_id' => $otherWarehouse->getKey(),
    ])->get();

    expect($scopedOut)->toHaveCount(0);

    $formatter = app(InventoryReportFormatter::class);
    $values = $formatter->values(InventoryReportType::CountVariance, $rows->first(), false);

    expect($values[5])->toBe(10.0)
        ->and($values[6])->toBe(8.0)
        ->and($values[7])->toBe(-2.0);

    expect(InventoryExportType::CountVariance->reports())->toBe([InventoryReportType::CountVariance]);
});
