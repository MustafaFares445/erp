<?php

declare(strict_types=1);

use App\Models\CustomerProfile;
use App\Models\CustomerReturnRequest;
use App\Models\InventoryOperation;
use App\Models\InvoiceLine;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceFloorOverride;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesProcurementRequirement;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CrmPermissionSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\Demo\DemoBaselineSeeder;
use Database\Seeders\Demo\DemoEmployeeRosterSeeder;
use Database\Seeders\Demo\DemoFixtures;
use Database\Seeders\Demo\DemoInventoryOpeningSeeder;
use Database\Seeders\Demo\DemoMasterDataSeeder;
use Database\Seeders\Demo\DemoMonthSeeder;
use Database\Seeders\EmployeePermissionSeeder;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\PackageTypeSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Database\Seeders\SupportPermissionSeeder;
use Database\Seeders\SystemPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

it('refuses to run the demo month in production', function (): void {
    $this->app['env'] = 'production';

    expect(fn () => app(DemoMonthSeeder::class)->run())
        ->toThrow(RuntimeException::class, 'disabled in production');

    expect(User::query()->where('email', 'demo.admin@ierp.test')->exists())->toBeFalse();
});

it('keeps the demo fixtures at the documented cardinalities', function (): void {
    $variants = collect(DemoFixtures::Products)->sum(fn (array $product): int => count($product['variants']));

    expect(DemoFixtures::Customers)->toHaveCount(15)
        ->and(DemoFixtures::Suppliers)->toHaveCount(7)
        ->and(DemoFixtures::Warehouses)->toHaveCount(3)
        ->and(DemoFixtures::Products)->toHaveCount(20)
        ->and($variants)->toBeBetween(35, 45);
});

it('seeds the foundation deterministically and rerunning adds nothing', function (): void {
    foreach ([
        CurrencySeeder::class, InventoryPermissionSeeder::class, EmployeePermissionSeeder::class,
        PurchasePermissionSeeder::class, SalesPermissionSeeder::class, SystemPermissionSeeder::class,
        AccountingPermissionSeeder::class, CrmPermissionSeeder::class, SupportPermissionSeeder::class,
        ChartOfAccountsSeeder::class, PackageTypeSeeder::class,
    ] as $seeder) {
        $this->seed($seeder);
    }

    $foundation = [DemoBaselineSeeder::class, DemoMasterDataSeeder::class, DemoEmployeeRosterSeeder::class, DemoInventoryOpeningSeeder::class];
    $snapshot = fn (): array => [
        CustomerProfile::query()->count(), Supplier::query()->count(), Product::query()->count(),
        ProductVariant::query()->count(), InventoryOperation::query()->count(), User::query()->count(),
    ];

    foreach ($foundation as $seeder) {
        $this->seed($seeder);
    }
    $first = $snapshot();

    foreach ($foundation as $seeder) {
        $this->seed($seeder);
    }

    expect($first[0])->toBe(15)
        ->and($first[1])->toBe(7)
        ->and($first[2])->toBe(20)
        ->and($snapshot())->toBe($first)
        ->and(Carbon::hasTestNow())->toBeFalse();
});

it('seeds real canonical cross-domain scenarios for shortage procurement, customer returns, and price-floor approval', function (): void {
    $this->seed(DemoMonthSeeder::class);

    $shortageOrder = Order::query()->where('notes', 'like', '[DEMO-SALES] O14 -%')->sole();

    expect(SalesProcurementRequirement::query()
        ->where('order_id', $shortageOrder->getKey())
        ->where('status', 'fulfilled')
        ->whereNotNull('purchase_order_id')
        ->whereNotNull('purchase_order_line_id')
        ->exists())->toBeTrue()
        ->and($shortageOrder->deliveries()->where('inventory_operations.stage', 'done')->exists())->toBeTrue()
        ->and(CustomerReturnRequest::query()
            ->where('status', 'converted')
            ->whereNotNull('resulting_inventory_return_id')
            ->exists())->toBeTrue()
        ->and(PriceFloorOverride::query()->count())->toBeGreaterThanOrEqual(1)
        ->and(OrderLine::query()->whereNotNull('price_floor_override_id')->exists())->toBeTrue()
        ->and(InvoiceLine::query()->whereNotNull('price_floor_override_id')->exists())->toBeTrue()
        ->and(Carbon::hasTestNow())->toBeFalse();
});
