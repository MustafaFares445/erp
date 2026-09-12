<?php

declare(strict_types=1);

use App\Enums\ReplenishmentCoverageSourceType;
use App\Enums\ReplenishmentRequirementStatus;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\ReplenishmentCoverage;
use App\Models\Warehouse;
use App\Models\WarehouseReplenishmentPolicy;
use App\Services\Inventory\ReplenishmentCoverageService;
use App\Services\Inventory\ReplenishmentRequirementService;
use App\Services\Inventory\ReplenishmentTransferSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows min max policy before stock exists and creates the target-max requirement', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $policy = WarehouseReplenishmentPolicy::query()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'min_quantity' => 20,
        'max_quantity' => 60,
        'is_active' => true,
    ]);

    $requirement = app(ReplenishmentRequirementService::class)->sync($policy);

    expect($requirement)->not->toBeNull()
        ->and((float) $requirement?->required_base_quantity)->toBe(60.0)
        ->and($requirement?->status)->toBe(ReplenishmentRequirementStatus::Open);
});

it('calculates requirement from saleable available stock rather than physical on hand', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $policy = WarehouseReplenishmentPolicy::query()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'min_quantity' => 20,
        'max_quantity' => 60,
        'is_active' => true,
    ]);
    InventoryStock::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'on_hand_quantity' => 18,
        'reserved_quantity' => 8,
        'damaged_quantity' => 0,
        'available_quantity' => 10,
    ]);

    $requirement = app(ReplenishmentRequirementService::class)->sync($policy);

    expect((float) $requirement?->required_base_quantity)->toBe(50.0);
});

it('tracks coverage explicitly and does not duplicate a source', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $policy = WarehouseReplenishmentPolicy::query()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'min_quantity' => 20,
        'max_quantity' => 60,
        'is_active' => true,
    ]);
    InventoryStock::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'on_hand_quantity' => 10,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 10,
    ]);
    $requirement = app(ReplenishmentRequirementService::class)->sync($policy);
    expect($requirement)->not->toBeNull();

    $coverageService = app(ReplenishmentCoverageService::class);
    $coverageService->attach(
        $requirement,
        ReplenishmentCoverageSourceType::PurchaseOrderLine,
        1001,
        30,
    );
    $coverageService->attach(
        $requirement,
        ReplenishmentCoverageSourceType::PurchaseOrderLine,
        1001,
        30,
    );

    $requirement = $requirement->refresh();

    expect(ReplenishmentCoverage::query()->count())->toBe(1)
        ->and((float) $requirement->covered_base_quantity)->toBe(30.0)
        ->and($requirement->status)->toBe(ReplenishmentRequirementStatus::PartiallyCovered);
});

it('suggests only surplus above another warehouse max before external purchase', function (): void {
    $targetWarehouse = Warehouse::factory()->create();
    $sourceWarehouse = Warehouse::factory()->create();
    $protectedWarehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();

    $targetPolicy = WarehouseReplenishmentPolicy::query()->create([
        'warehouse_id' => $targetWarehouse->id,
        'product_variant_id' => $variant->id,
        'min_quantity' => 20,
        'max_quantity' => 60,
        'is_active' => true,
    ]);
    WarehouseReplenishmentPolicy::query()->create([
        'warehouse_id' => $sourceWarehouse->id,
        'product_variant_id' => $variant->id,
        'min_quantity' => 20,
        'max_quantity' => 60,
        'is_active' => true,
    ]);
    WarehouseReplenishmentPolicy::query()->create([
        'warehouse_id' => $protectedWarehouse->id,
        'product_variant_id' => $variant->id,
        'min_quantity' => 20,
        'max_quantity' => 60,
        'is_active' => true,
    ]);

    InventoryStock::factory()->create([
        'warehouse_id' => $targetWarehouse->id,
        'product_variant_id' => $variant->id,
        'on_hand_quantity' => 10,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 10,
    ]);
    InventoryStock::factory()->create([
        'warehouse_id' => $sourceWarehouse->id,
        'product_variant_id' => $variant->id,
        'on_hand_quantity' => 100,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 100,
    ]);
    InventoryStock::factory()->create([
        'warehouse_id' => $protectedWarehouse->id,
        'product_variant_id' => $variant->id,
        'on_hand_quantity' => 55,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 55,
    ]);

    $requirement = app(ReplenishmentRequirementService::class)->sync($targetPolicy);
    expect($requirement)->not->toBeNull();
    $suggestions = app(ReplenishmentTransferSuggestionService::class)->suggest($requirement);

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]->sourceWarehouseId)->toBe($sourceWarehouse->id)
        ->and($suggestions[0]->suggestedBaseQuantity)->toBe(40.0);
});

it('rejects invalid min max policy values', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();

    expect(fn () => WarehouseReplenishmentPolicy::query()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'min_quantity' => 20,
        'max_quantity' => 20,
        'is_active' => true,
    ]))->toThrow(DomainException::class);
});
