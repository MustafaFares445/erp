<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\UserType;
use App\Filament\Resources\Adjustments\AdjustmentResource;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\Transfers\TransferResource;
use App\Filament\Resources\Warehouses\WarehouseResource;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('seeds an authorized system administrator and the inventory permission catalogue', function (): void {
    $this->seed();

    $admin = User::query()->where('email', 'admin@ierp.com')->sole();

    expect($admin->user_type)->toBe(UserType::Admin)
        ->and($admin->getAllPermissions()->pluck('name')->all())
        ->toEqualCanonicalizing(InventoryPermission::values())
        ->and(Permission::query()->where('guard_name', 'web')->pluck('name')->all())
        ->toEqualCanonicalizing(InventoryPermission::values());

    expect(Brand::query()->whereIn('code', ['FORMLABS', 'DENTSPLY-SIRONA', 'IVOCLAR'])->count())->toBe(3)
        ->and(ProductCategory::query()->count())->toBe(3)
        ->and(Unit::query()->whereIn('symbol', ['EA', 'L'])->count())->toBe(2)
        ->and(Product::query()->count())->toBe(7)
        ->and(ProductVariant::query()->count())->toBe(15)
        ->and(ProductVariant::query()->where('sku', 'like', 'DEMO-%')->exists())->toBeFalse();

    foreach ([
        WarehouseResource::getUrl(),
        StockLevelResource::getUrl(),
        StockMovementResource::getUrl(),
        AdjustmentResource::getUrl(),
        TransferResource::getUrl(),
    ] as $url) {
        $this->actingAs($admin)->get($url)->assertOk();
    }
});
