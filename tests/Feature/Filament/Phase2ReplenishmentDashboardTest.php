<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Widgets\InventoryKeyMetrics;
use App\Filament\Widgets\PurchasingStatistics;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\ReplenishmentRequirement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseReplenishmentPolicy;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

it('shows zero replenishment work queues to an authorized inventory viewer', function (): void {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo([
        InventoryPermission::StockView->value,
        InventoryPermission::ReplenishmentPolicyView->value,
    ]);
    $this->actingAs($viewer);

    $widget = app(InventoryKeyMetrics::class);
    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);

    expect($stats)->toHaveCount(5)
        ->and($stats[3]->getValue())->toBe('0')
        ->and($stats[4]->getValue())->toBe('0');
});

it('shows open requirements and internal transfer suggestions on inventory and only residual external need on purchasing', function (): void {
    $firstVariant = ProductVariant::factory()->create();
    $secondVariant = ProductVariant::factory()->create();

    $firstTarget = Warehouse::factory()->create();
    $firstSource = Warehouse::factory()->create();
    $secondTarget = Warehouse::factory()->create();
    $secondSource = Warehouse::factory()->create();

    InventoryStock::factory()->create([
        'warehouse_id' => $firstTarget->id,
        'product_variant_id' => $firstVariant->id,
        'on_hand_quantity' => 10,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 10,
    ]);
    InventoryStock::factory()->create([
        'warehouse_id' => $firstSource->id,
        'product_variant_id' => $firstVariant->id,
        'on_hand_quantity' => 100,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 100,
    ]);
    InventoryStock::factory()->create([
        'warehouse_id' => $secondTarget->id,
        'product_variant_id' => $secondVariant->id,
        'on_hand_quantity' => 30,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 30,
    ]);
    InventoryStock::factory()->create([
        'warehouse_id' => $secondSource->id,
        'product_variant_id' => $secondVariant->id,
        'on_hand_quantity' => 100,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 100,
    ]);

    WarehouseReplenishmentPolicy::factory()->create([
        'warehouse_id' => $firstTarget->id,
        'product_variant_id' => $firstVariant->id,
        'min_quantity' => 20,
        'max_quantity' => 60,
        'is_active' => true,
    ]);
    WarehouseReplenishmentPolicy::factory()->create([
        'warehouse_id' => $firstSource->id,
        'product_variant_id' => $firstVariant->id,
        'min_quantity' => 20,
        'max_quantity' => 60,
        'is_active' => true,
    ]);
    WarehouseReplenishmentPolicy::factory()->create([
        'warehouse_id' => $secondTarget->id,
        'product_variant_id' => $secondVariant->id,
        'min_quantity' => 40,
        'max_quantity' => 60,
        'is_active' => true,
    ]);
    WarehouseReplenishmentPolicy::factory()->create([
        'warehouse_id' => $secondSource->id,
        'product_variant_id' => $secondVariant->id,
        'min_quantity' => 20,
        'max_quantity' => 60,
        'is_active' => true,
    ]);

    expect(ReplenishmentRequirement::query()->active()->count())->toBe(2);

    $viewer = User::factory()->create();
    $viewer->givePermissionTo([
        InventoryPermission::StockView->value,
        InventoryPermission::ReplenishmentPolicyView->value,
    ]);
    $this->actingAs($viewer);

    $inventoryWidget = app(InventoryKeyMetrics::class);
    $inventoryStats = new ReflectionMethod($inventoryWidget, 'getStats')->invoke($inventoryWidget);

    expect($inventoryStats)->toHaveCount(5)
        ->and($inventoryStats[3]->getValue())->toBe('2')
        ->and($inventoryStats[3]->getDescription())->toContain('80.000000')
        ->and($inventoryStats[4]->getValue())->toBe('2')
        ->and($inventoryStats[4]->getDescription())->toContain('70.000000');

    $purchasingWidget = app(PurchasingStatistics::class);
    $purchasingStats = new ReflectionMethod($purchasingWidget, 'getStats')->invoke($purchasingWidget);

    expect($purchasingStats[4]->getValue())->toBe('1')
        ->and($purchasingStats[4]->getDescription())->toContain('10.000000');
});

it('resynchronizes the durable requirement when a stock position changes', function (): void {
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $stock = InventoryStock::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'on_hand_quantity' => 50,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 50,
    ]);
    WarehouseReplenishmentPolicy::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'min_quantity' => 20,
        'max_quantity' => 60,
        'is_active' => true,
    ]);

    expect(ReplenishmentRequirement::query()->active()->count())->toBe(0);

    $stock->forceFill([
        'on_hand_quantity' => 10,
        'available_quantity' => 10,
    ])->save();

    $requirement = ReplenishmentRequirement::query()->active()->first();

    expect($requirement)->not->toBeNull()
        ->and((float) $requirement?->required_base_quantity)->toBe(50.0);
});
