<?php

declare(strict_types=1);

use App\Filament\Resources\InventoryOperations\Pages\ListDeliveries;
use App\Filament\Resources\InventoryOperations\Pages\ListInternalTransfers;
use App\Filament\Resources\InventoryReservations\Pages\ListInventoryReservations;
use App\Filament\Resources\Products\Pages\ManageProductQuantities;
use App\Filament\Resources\Products\Pages\ManageProductVariants as ManageVariantsOfProduct;
use App\Filament\Resources\ProductVariants\Pages\ManageProductVariants;
use App\Filament\Resources\StockLevels\Pages\ListStockLevels;
use App\Filament\Tables\TableFit;
use App\Filament\Widgets\InventoryExpiringLots;
use App\Filament\Widgets\PurchasingAttentionQueue;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Database\Seeders\SystemPermissionSeeder;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SystemPermissionSeeder)->run();
    (new InventoryPermissionSeeder)->run();
    (new PurchasePermissionSeeder)->run();

    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');
    $this->actingAs($admin);
});

it('keeps the warehouses and schedule on the operation lists where they identify the operation', function (string $page, array $shown, array $hidden): void {
    $list = Livewire::test($page)->instance();

    foreach ($shown as $column) {
        expect($list->isTableColumnToggledHidden($column))->toBeFalse("{$column} should stay shown");
    }

    foreach ($hidden as $column) {
        expect($list->isTableColumnToggledHidden($column))->toBeTrue("{$column} should start hidden");
    }
})->with([
    'internal transfers' => [ListInternalTransfers::class, ['sourceWarehouse.name', 'destinationWarehouse.name', 'scheduled_at'], []],
    'deliveries' => [ListDeliveries::class, ['sourceWarehouse.name', 'scheduled_at'], ['delivery_type']],
]);

it("leaves the product name and type off a product's own variants page", function (): void {
    $product = Product::factory()->create();

    Livewire::test(ManageVariantsOfProduct::class, ['record' => $product->getRouteKey()])
        ->assertTableColumnHidden('product.name')
        ->assertTableColumnHidden('product.product_type');

    Livewire::test(ManageProductVariants::class)
        ->assertTableColumnVisible('product.name')
        ->assertTableColumnVisible('product.product_type');
});

it("starts the in-transit quantity hidden only on a product's narrower quantities page", function (): void {
    $product = Product::factory()->create();

    expect(Livewire::test(ManageProductQuantities::class, ['record' => $product->getRouteKey()])->instance()->isTableColumnToggledHidden('in_transit_quantity'))->toBeTrue()
        ->and(Livewire::test(ListStockLevels::class)->instance()->isTableColumnToggledHidden('in_transit_quantity'))->toBeFalse();
});

it('keeps releasing a reservation inline and tucks view into the overflow menu', function (): void {
    $actions = collect(Livewire::test(ListInventoryReservations::class)->instance()->getTable()->getRecordActions());

    expect($actions->reject(static fn (mixed $action): bool => $action instanceof ActionGroup)->map(static fn (Action $action): string => (string) $action->getName())->values()->all())->toBe(['release'])
        ->and(array_keys($actions->first(static fn (mixed $action): bool => $action instanceof ActionGroup)->getFlatActions()))->toBe(['view']);
});

it('fits the dashboard widget tables with the shared policy and drops their secondary columns', function (string $widget, array $hidden): void {
    $component = Livewire::test($widget)->instance();

    expect($component->getTable()->getExtraAttributes()['class'] ?? null)->toBe(TableFit::CSS_CLASS);

    foreach ($hidden as $column) {
        expect($component->isTableColumnToggledHidden($column))->toBeTrue("{$column} should start hidden");
    }
})->with([
    'expiring lots' => [InventoryExpiringLots::class, ['warehouse.name', 'lot.expires_at']],
    'purchasing attention queue' => [PurchasingAttentionQueue::class, ['expected_at']],
]);
