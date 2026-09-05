<?php

declare(strict_types=1);

use App\Enums\CrmPermission;
use App\Enums\InventoryImportItemStatus;
use App\Enums\InventoryImportRunStatus;
use App\Enums\InventoryPermission;
use App\Enums\InventoryReportType;
use App\Enums\MovementType;
use App\Enums\ProductType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\Brand;
use App\Models\CustomerPricingTier;
use App\Models\InventoryImportItem;
use App\Models\InventoryImportRun;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryMovement;
use App\Models\InventorySetting;
use App\Models\InventoryStock;
use App\Models\PriceFloorOverride;
use App\Models\PriceHistory;
use App\Models\PricingTier;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryReportFormatter;
use App\Services\Inventory\InventoryReportService;
use Database\Seeders\CrmPermissionSeeder;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('normalizes only supported report filters and rejects invalid date ranges', function (): void {
    $service = app(InventoryReportService::class);

    expect($service->normalizeFilters(InventoryReportType::Movements, [
        'warehouse_id' => '12',
        'movement_type' => ' receipt ',
        'stock_condition_from' => ' saleable ',
        'source_type' => ' inventory_operation ',
        'from' => '2026-01-01',
        'until' => '2026-01-31',
        'country_code' => 'sy',
        'ignored' => 'value',
    ]))->toBe([
        'warehouse_id' => 12,
        'movement_type' => 'receipt',
        'stock_condition_from' => 'saleable',
        'source_type' => 'inventory_operation',
        'from' => '2026-01-01',
        'until' => '2026-01-31',
    ]);

    expect(fn () => $service->normalizeFilters(InventoryReportType::Movements, [
        'from' => '2026-02-01',
        'until' => '2026-01-01',
    ]))->toThrow(DomainException::class);
});

it('formats enriched canonical movement and serialized receipt context without legacy receipt reads', function (): void {
    $service = app(InventoryReportService::class);
    $formatter = app(InventoryReportFormatter::class);
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'status' => SerializedInventoryUnitStatus::Available,
    ]);

    $movement = InventoryMovement::factory()
        ->for($variant, 'productVariant')
        ->for($warehouse)
        ->create([
            'movement_type' => MovementType::Receipt,
            'quantity' => '5.000000',
            'transaction_quantity' => '5.000000',
            'transaction_unit_id' => $variant->unit_id,
            'conversion_factor_snapshot' => '1.000000',
            'base_quantity_delta' => '5.000000',
            'stock_condition_from' => StockCondition::Saleable,
            'stock_condition_to' => StockCondition::Saleable,
            'condition_from_on_hand_before' => '0.000000',
            'condition_from_on_hand_after' => '5.000000',
            'condition_from_reserved_before' => '0.000000',
            'condition_from_reserved_after' => '0.000000',
            'condition_to_on_hand_before' => '0.000000',
            'condition_to_on_hand_after' => '5.000000',
            'condition_to_reserved_before' => '0.000000',
            'condition_to_reserved_after' => '0.000000',
            'serialized_inventory_unit_id' => $unit->getKey(),
            'source_type' => 'inventory_operation',
            'source_id' => 777,
            'source_line_type' => 'inventory_operation_line',
            'source_line_id' => 888,
        ]);

    $reportedMovement = $service->query(InventoryReportType::Movements, [
        'stock_condition_from' => StockCondition::Saleable->value,
        'source_type' => 'inventory_operation',
    ])->sole();

    $values = $formatter->values(InventoryReportType::Movements, $reportedMovement, false);

    expect($reportedMovement->is($movement))->toBeTrue()
        ->and($values)->toHaveCount(count($formatter->headings(InventoryReportType::Movements, false)))
        ->and($values[6])->toBe(5.0)
        ->and($values[8])->toBe(1.0)
        ->and($values[9])->toBe(5.0)
        ->and($values[10])->toBe(StockCondition::Saleable->value)
        ->and($values[24])->toBe('inventory_operation')
        ->and($values[26])->toBe('inventory_operation_line');

    $reportedDevice = $service->query(InventoryReportType::Devices, [
        'product_variant_id' => $variant->getKey(),
    ])->whereKey($unit->getKey())->firstOrFail();
    $deviceValues = $formatter->values(InventoryReportType::Devices, $reportedDevice, false);

    expect($deviceValues[6])->toBe('inventory_operation #777')
        ->and($reportedDevice->receiptMovement?->is($movement))->toBeTrue();
});

it('enforces report source and sensitive pricing permissions', function (): void {
    (new InventoryPermissionSeeder)->run();
    $service = app(InventoryReportService::class);
    $actor = User::factory()->create();
    $actor->givePermissionTo([
        InventoryPermission::ReportView->value,
        InventoryPermission::StockView->value,
        InventoryPermission::CatalogView->value,
    ]);

    expect($service->canView($actor, InventoryReportType::StockLevels))->toBeTrue()
        ->and($service->canView($actor, InventoryReportType::Movements))->toBeFalse()
        ->and($service->canView($actor, InventoryReportType::SupplierComparison))->toBeFalse()
        ->and($service->availableReports($actor))->toContain(
            InventoryReportType::Catalog,
            InventoryReportType::StockLevels,
            InventoryReportType::Devices,
            InventoryReportType::ExpiryLots,
            InventoryReportType::QuarantineAgeing,
        )->not->toContain(InventoryReportType::PriceHistory);

    $actor->givePermissionTo(InventoryPermission::PricingView->value);

    expect($service->canView($actor, InventoryReportType::SupplierComparison))->toBeTrue()
        ->and($service->canView($actor, InventoryReportType::PriceHistory))->toBeTrue();
});

it('lets a CRM actor view pricing reports without any inventory permission', function (): void {
    (new CrmPermissionSeeder)->run();
    $service = app(InventoryReportService::class);
    $actor = User::factory()->create();
    $actor->givePermissionTo(CrmPermission::ReportView->value);

    expect($service->canView($actor, InventoryReportType::PriceHistory))->toBeTrue()
        ->and($service->canView($actor, InventoryReportType::StockLevels))->toBeFalse();
});

it('applies the shared filters to every report source', function (): void {
    $service = app(InventoryReportService::class);
    $warehouse = Warehouse::factory()->create();
    $otherWarehouse = Warehouse::factory()->create();
    $category = ProductCategory::factory()->create(['name' => 'Report category']);
    $otherCategory = ProductCategory::factory()->create(['name' => 'Other category']);
    $brand = Brand::factory()->create(['name' => 'Report brand', 'code' => 'REPORT']);
    $product = Product::factory()->create(['category_id' => $category->getKey(), 'brand_id' => $brand->getKey()]);
    $otherProduct = Product::factory()->create(['category_id' => $otherCategory->getKey()]);
    $variant = ProductVariant::factory()->for($product)->create();
    $otherVariant = ProductVariant::factory()->for($otherProduct)->create();

    expect(reportIds($service->query(InventoryReportType::Catalog, ['category_id' => $category->getKey()])))
        ->toBe([$variant->getKey()]);

    $stock = InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => 5]);
    InventoryStock::factory()->for($otherVariant)->for($otherWarehouse)->create(['available_quantity' => 5]);
    expect(reportIds($service->query(InventoryReportType::StockLevels, ['warehouse_id' => $warehouse->getKey()])))
        ->toBe([$stock->getKey()]);

    $movement = InventoryMovement::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'movement_type' => MovementType::Receipt,
    ]);
    InventoryMovement::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'movement_type' => MovementType::Sale,
    ]);
    expect(reportIds($service->query(InventoryReportType::Movements, ['movement_type' => MovementType::Receipt->value])))
        ->toBe([$movement->getKey()]);

    $device = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'serial_number' => 'REPORT-SERIAL',
        'status' => SerializedInventoryUnitStatus::Available,
    ]);
    SerializedInventoryUnit::factory()->create(['status' => SerializedInventoryUnitStatus::Pending]);
    expect(reportIds($service->query(InventoryReportType::Devices, ['identity' => 'REPORT-SER'])))
        ->toBe([$device->getKey()]);

    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'expires_at' => today()->subDay(),
    ]);
    InventoryLot::factory()->create(['expires_at' => today()->addYear()]);
    expect(reportIds($service->query(InventoryReportType::ExpiryLots, ['expiry_state' => 'expired'])))
        ->toBe([$lot->getKey()]);

    $quarantineBalance = InventoryLotBalance::query()->updateOrCreate([
        'inventory_lot_id' => $lot->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Quarantine,
    ], [
        'on_hand_base_quantity' => '3.000000',
        'reserved_base_quantity' => '0.000000',
        'created_at' => now()->subDays(40),
        'updated_at' => now()->subDays(40),
    ]);
    InventoryMovement::factory()
        ->for($variant, 'productVariant')
        ->for($warehouse)
        ->create([
            'inventory_lot_id' => $lot->getKey(),
            'movement_type' => MovementType::Return,
            'quantity' => '3.000000',
            'stock_condition_from' => StockCondition::Saleable,
            'stock_condition_to' => StockCondition::Quarantine,
            'source_type' => 'inventory_return',
            'source_id' => 909,
            'created_at' => now()->subDays(40),
        ]);
    expect(reportIds($service->query(InventoryReportType::QuarantineAgeing, [
        'warehouse_id' => $warehouse->getKey(),
        'product_variant_id' => $variant->getKey(),
    ])))->toBe([$quarantineBalance->getKey()]);

    $supplier = Supplier::factory()->create(['name' => 'Report supplier', 'code' => 'SUP-REPORT']);
    $supplierReference = SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'supplier_name' => $supplier->name,
        'supplier_item_number' => 'SUP-ITEM-1',
        'country_code' => 'SY',
        'purchase_cost' => 10,
        'currency_code' => 'USD',
        'is_active' => true,
    ]);
    SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $otherVariant->getKey(),
        'supplier_name' => $supplier->name,
        'supplier_item_number' => 'SUP-ITEM-2',
        'country_code' => 'TR',
        'purchase_cost' => 300,
        'currency_code' => 'TRY',
        'is_active' => true,
    ]);
    expect(reportIds($service->query(InventoryReportType::SupplierComparison, ['country_code' => 'sy'])))
        ->toBe([$supplierReference->getKey()]);

    $priceHistory = PriceHistory::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'cost_price' => 10,
        'base_price' => 12,
        'min_price' => 11,
        'markup_percent' => 20,
    ]);
    PriceHistory::factory()->create([
        'product_variant_id' => $otherVariant->getKey(),
        'cost_price' => 20,
        'base_price' => 24,
        'min_price' => 22,
        'markup_percent' => 20,
    ]);
    expect(reportIds($service->query(InventoryReportType::PriceHistory, ['product_variant_id' => $variant->getKey()])))
        ->toBe([$priceHistory->getKey()]);

    $customer = User::factory()->customer()->create();
    $tier = PricingTier::factory()->create(['customer_user_id' => $customer->getKey(), 'is_active' => true]);
    PricingTier::factory()->create(['is_active' => false]);
    expect(reportIds($service->query(InventoryReportType::PricingTiers, ['customer_user_id' => $customer->getKey()])))
        ->toBe([$tier->getKey()]);

    $assignment = CustomerPricingTier::factory()->create([
        'customer_user_id' => $customer->getKey(),
        'pricing_tier_id' => $tier->getKey(),
        'is_active' => true,
    ]);
    CustomerPricingTier::factory()->create(['is_active' => false]);
    expect(reportIds($service->query(InventoryReportType::CustomerAssignments, ['customer_user_id' => $customer->getKey()])))
        ->toBe([$assignment->getKey()]);

    $override = PriceFloorOverride::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'customer_user_id' => $customer->getKey(),
        'attempted_price' => 8,
        'min_price' => 9,
        'approved_by' => User::factory(),
        'approved_at' => now(),
        'reason' => 'Approved for report test.',
    ]);
    PriceFloorOverride::factory()->create([
        'product_variant_id' => $otherVariant->getKey(),
        'attempted_price' => 18,
        'min_price' => 19,
        'approved_by' => User::factory(),
        'approved_at' => now(),
        'reason' => 'Other override.',
    ]);
    expect(reportIds($service->query(InventoryReportType::FloorOverrides, ['product_variant_id' => $variant->getKey()])))
        ->toBe([$override->getKey()]);

    $run = InventoryImportRun::factory()->create(['status' => InventoryImportRunStatus::Confirmed]);
    InventoryImportRun::factory()->create(['status' => InventoryImportRunStatus::Failed]);
    expect(reportIds($service->query(InventoryReportType::ImportRuns, ['status' => InventoryImportRunStatus::Confirmed->value])))
        ->toBe([$run->getKey()]);

    $item = InventoryImportItem::factory()->for($run, 'run')->create(['status' => InventoryImportItemStatus::Applied]);
    InventoryImportItem::factory()->create(['status' => InventoryImportItemStatus::Invalid]);
    expect(reportIds($service->query(InventoryReportType::ImportResults, [
        'inventory_import_run_id' => $run->getKey(),
        'status' => InventoryImportItemStatus::Applied->value,
    ])))->toBe([$item->getKey()]);

    $formatter = app(InventoryReportFormatter::class);
    foreach (InventoryReportType::cases() as $type) {
        $record = $service->query($type)->firstOrFail();

        expect($formatter->values($type, $record, true))
            ->toHaveCount(count($formatter->headings($type, true)));
    }
});

it('keeps reports read only by issuing no database writes while querying every source', function (): void {
    $service = app(InventoryReportService::class);
    expect(InventorySetting::query()->count())->toBe(0);
    $before = collect(InventoryReportType::cases())
        ->mapWithKeys(fn (InventoryReportType $type): array => [$type->value => $service->query($type)->count()])
        ->all();

    foreach (InventoryReportType::cases() as $type) {
        $service->query($type)->limit(10)->get();
    }

    $after = collect(InventoryReportType::cases())
        ->mapWithKeys(fn (InventoryReportType $type): array => [$type->value => $service->query($type)->count()])
        ->all();

    expect($after)->toBe($before);
    expect(InventorySetting::query()->count())->toBe(0);
});

it('covers report filter boundary values and every stock and expiry state', function (): void {
    $service = app(InventoryReportService::class);
    $brand = Brand::factory()->create(['name' => 'Boundary brand', 'code' => 'BOUNDARY']);
    $matchingProduct = Product::factory()->for($brand)->create();
    $matchingVariant = ProductVariant::factory()->for($matchingProduct)->create();
    ProductVariant::factory()->create();

    expect(reportIds($service->query(InventoryReportType::Catalog, [
        'brand_id' => $brand->getKey(),
    ])))->toBe([$matchingVariant->getKey()]);

    $outOfStock = InventoryStock::factory()->create([
        'on_hand_quantity' => 0,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 0,
        'reorder_level' => 5,
    ]);
    $lowStock = InventoryStock::factory()->create([
        'on_hand_quantity' => 2,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 2,
        'reorder_level' => 5,
    ]);
    $available = InventoryStock::factory()->create([
        'on_hand_quantity' => 10,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 10,
        'reorder_level' => 5,
    ]);

    expect(reportIds($service->query(InventoryReportType::StockLevels, [
        'availability_state' => 'out_of_stock',
    ])))->toBe([$outOfStock->getKey()])
        ->and(reportIds($service->query(InventoryReportType::StockLevels, [
            'availability_state' => 'low_stock',
        ])))->toBe([$lowStock->getKey()])
        ->and(reportIds($service->query(InventoryReportType::StockLevels, [
            'availability_state' => 'available',
        ])))->toBe([$lowStock->getKey(), $available->getKey()]);

    $expiring = InventoryLot::factory()->create(['expires_at' => today()->addDay()]);
    $healthy = InventoryLot::factory()->create(['expires_at' => today()->addDays(31)]);
    $withoutExpiry = InventoryLot::factory()->create(['expires_at' => null]);

    expect(reportIds($service->query(InventoryReportType::ExpiryLots, [
        'expiry_state' => 'expiring',
    ])))->toBe([$expiring->getKey()])
        ->and(reportIds($service->query(InventoryReportType::ExpiryLots, [
            'expiry_state' => 'healthy',
        ])))->toBe([$healthy->getKey()])
        ->and(reportIds($service->query(InventoryReportType::ExpiryLots, [
            'expiry_state' => 'no_expiry',
        ])))->toBe([$withoutExpiry->getKey()]);

    $creator = User::factory()->create();
    $run = InventoryImportRun::factory()->create(['created_by' => $creator->getKey()]);
    $item = InventoryImportItem::factory()->for($run, 'run')->create();
    InventoryImportItem::factory()->create();

    expect(reportIds($service->query(InventoryReportType::ImportResults, [
        'created_by' => $creator->getKey(),
    ])))->toBe([$item->getKey()]);
});

it('filters pricing tiers and customer assignments by product, tier type, active state and eligibility', function (): void {
    $service = app(InventoryReportService::class);
    $product = Product::factory()->create();
    $otherProduct = Product::factory()->create();

    $matchingTier = PricingTier::factory()->productScoped()->create(['tier_type' => 'product_scoped', 'is_active' => true]);
    $matchingTier->products()->attach($product->getKey());
    $otherTier = PricingTier::factory()->productScoped()->create(['tier_type' => 'product_scoped', 'is_active' => true]);
    $otherTier->products()->attach($otherProduct->getKey());

    expect(reportIds($service->query(InventoryReportType::PricingTiers, ['product_id' => $product->getKey()])))
        ->toBe([$matchingTier->getKey()])
        ->and(reportIds($service->query(InventoryReportType::PricingTiers, ['is_active' => true])))
        ->toBe([$matchingTier->getKey(), $otherTier->getKey()]);

    $current = PricingTier::factory()->create(['is_active' => true, 'valid_from' => null, 'valid_until' => null]);
    $scheduled = PricingTier::factory()->create(['is_active' => true, 'valid_from' => today()->addWeek(), 'valid_until' => null]);
    $expired = PricingTier::factory()->create(['is_active' => true, 'valid_from' => today()->subMonth(), 'valid_until' => today()->subDay()]);

    expect(reportIds($service->query(InventoryReportType::PricingTiers, ['eligibility_state' => 'current'])))
        ->toBe([$matchingTier->getKey(), $otherTier->getKey(), $current->getKey()])
        ->and(reportIds($service->query(InventoryReportType::PricingTiers, ['eligibility_state' => 'scheduled'])))
        ->toBe([$scheduled->getKey()])
        ->and(reportIds($service->query(InventoryReportType::PricingTiers, ['eligibility_state' => 'expired'])))
        ->toBe([$expired->getKey()]);

    $customer = User::factory()->customer()->create();
    $matchingAssignment = CustomerPricingTier::factory()->create([
        'customer_user_id' => $customer->getKey(),
        'pricing_tier_id' => $matchingTier->getKey(),
        'is_active' => true,
    ]);
    CustomerPricingTier::factory()->create([
        'pricing_tier_id' => $otherTier->getKey(),
        'is_active' => true,
    ]);

    expect(reportIds($service->query(InventoryReportType::CustomerAssignments, [
        'product_id' => $product->getKey(),
        'tier_type' => 'product_scoped',
    ])))
        ->toBe([$matchingAssignment->getKey()])
        ->and(reportIds($service->query(InventoryReportType::CustomerAssignments, ['product_id' => $product->getKey()])))
        ->toBe([$matchingAssignment->getKey()])
        ->and(reportIds($service->query(InventoryReportType::CustomerAssignments, ['is_active' => true])))
        ->toContain($matchingAssignment->getKey());
});

it('filters the catalog and stock-levels reports by product type', function (): void {
    $service = app(InventoryReportService::class);
    $machine = ProductVariant::factory()->machine()->create();
    $grain = ProductVariant::factory()->grain()->create();

    expect(reportIds($service->query(InventoryReportType::Catalog, [
        'product_type' => ProductType::Machine->value,
    ])))->toBe([$machine->getKey()]);

    $machineStock = InventoryStock::factory()->for($machine)->create();
    InventoryStock::factory()->for($grain)->create();

    expect(reportIds($service->query(InventoryReportType::StockLevels, [
        'product_type' => ProductType::Machine->value,
    ])))->toBe([$machineStock->getKey()]);
});

it('normalizes empty invalid and date-boundary report filters', function (): void {
    $service = app(InventoryReportService::class);
    $inside = InventoryMovement::factory()->create(['created_at' => '2026-01-15 12:00:00']);
    InventoryMovement::factory()->create(['created_at' => '2025-12-31 12:00:00']);
    InventoryMovement::factory()->create(['created_at' => '2026-02-01 12:00:00']);

    expect($service->normalizeFilters(InventoryReportType::Catalog, [
        'product_id' => 0,
        'is_active' => 'not-a-boolean',
        'status' => ' ',
    ]))->toBe([])
        ->and(reportIds($service->query(InventoryReportType::Movements, [
            'from' => '2026-01-01',
            'until' => '2026-01-31',
        ])))->toBe([$inside->getKey()]);

    expect(fn () => $service->normalizeFilters(InventoryReportType::Movements, [
        'from' => 'not-a-date',
    ]))->toThrow(DomainException::class);
});

it('rejects a mismatched model for every report formatter', function (): void {
    $formatter = app(InventoryReportFormatter::class);
    $wrongRecord = Warehouse::factory()->create();

    foreach (InventoryReportType::cases() as $type) {
        expect(fn () => $formatter->values($type, $wrongRecord, false))
            ->toThrow(LogicException::class, sprintf('Invalid model supplied for the %s report.', $type->value));
    }
});

it('rejects report values that cannot be encoded as json', function (): void {
    $formatter = app(InventoryReportFormatter::class);
    $method = new ReflectionMethod($formatter, 'json');

    $handle = fopen('php://memory', 'rb');

    try {
        expect(fn (): mixed => $method->invoke($formatter, $handle))
            ->toThrow(LogicException::class, 'Unable to encode an inventory report value.');
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
    }
});

/**
 * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
 * @return list<int>
 */
function reportIds(Builder $query): array
{
    return $query->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
}
