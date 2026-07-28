<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Models\CustomerPricingTier;
use App\Models\PriceFloorOverride;
use App\Models\PriceHistory;
use App\Models\PricingTier;
use App\Models\User;
use Database\Seeders\InventoryDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('idempotently seeds example pricing tiers, assignments, history, and a floor override', function (): void {
    $seeder = new InventoryDemoSeeder;

    $seeder->run();
    $seeder->run();

    expect(PricingTier::query()->count())->toBe(2)
        ->and(PricingTier::query()->whereNull('customer_user_id')->count())->toBe(1)
        ->and(CustomerPricingTier::query()->count())->toBe(1)
        ->and(PriceHistory::query()->where('markup_percent', 40)->count())->toBe(1)
        ->and(PriceFloorOverride::query()->count())->toBe(1);

    $admin = User::query()->where('email', 'admin@ierp.com')->sole();

    expect($admin->hasPermissionTo(InventoryPermission::PricingManage->value))->toBeTrue();
});
