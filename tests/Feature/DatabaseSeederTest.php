<?php

declare(strict_types=1);

use App\Enums\AccountingPermission;
use App\Enums\CrmPermission;
use App\Enums\EmployeePermission;
use App\Enums\InventoryPermission;
use App\Enums\PurchasePermission;
use App\Enums\SalesPermission;
use App\Enums\SupportPermission;
use App\Enums\UserType;
use App\Filament\Resources\Adjustments\AdjustmentResource;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
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

it('seeds an authorized system administrator and the permission catalogue', function (): void {
    $this->seed();

    $admin = User::query()->where('email', 'admin@ierp.com')->sole();
    $permissions = [...InventoryPermission::values(), ...CrmPermission::values(), ...EmployeePermission::values(), ...SupportPermission::values(), ...AccountingPermission::values(), ...PurchasePermission::values(), ...SalesPermission::values()];

    expect($admin->user_type)->toBe(UserType::Admin)
        ->and($admin->getAllPermissions()->pluck('name')->all())
        ->toEqualCanonicalizing($permissions)
        ->and(Permission::query()->where('guard_name', 'web')->pluck('name')->all())
        ->toEqualCanonicalizing($permissions);

    expect(Brand::query()->whereIn('code', ['FORMLABS', 'DENTSPLY-SIRONA', 'IVOCLAR'])->count())->toBe(3)
        ->and(ProductCategory::query()->count())->toBe(4)
        ->and(Unit::query()->whereIn('symbol', ['EA', 'L', 'SACK', 'KG'])->count())->toBe(4)
        ->and(Product::query()->count())->toBe(8)
        ->and(ProductVariant::query()->count())->toBe(16)
        ->and(ProductVariant::query()->where('sku', 'like', 'DEMO-%')->exists())->toBeFalse();

    foreach ([
        WarehouseResource::getUrl(),
        StockLevelResource::getUrl(),
        StockMovementResource::getUrl(),
        AdjustmentResource::getUrl(),
        InventoryOperationResource::getUrl(),
    ] as $url) {
        $this->actingAs($admin)->get($url)->assertOk();
    }
});
