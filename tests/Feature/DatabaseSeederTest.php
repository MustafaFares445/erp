<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\UserType;
use App\Filament\Resources\Adjustments\AdjustmentResource;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\Transfers\TransferResource;
use App\Filament\Resources\Warehouses\WarehouseResource;
use App\Models\InventoryAdjustment;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
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

    expect(Warehouse::query()->whereIn('code', ['DEMO-CENTRAL', 'DEMO-WEST'])->count())->toBe(2)
        ->and(InventoryStock::query()->count())->toBe(3)
        ->and(InventoryMovement::query()->count())->toBe(3)
        ->and(InventoryAdjustment::query()->count())->toBe(1)
        ->and(StockTransfer::query()->count())->toBe(1);

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
