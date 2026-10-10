<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\StockCondition;
use App\Filament\Pages\InventoryDashboard;
use App\Filament\Widgets\InventoryExpiringLots;
use App\Filament\Widgets\InventoryKeyMetrics;
use App\Filament\Widgets\InventoryLowStock;
use App\Filament\Widgets\InventoryMovementsTrend;
use App\Filament\Widgets\InventoryRecentMovements;
use App\Filament\Widgets\InventoryStockValue;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventorySetting;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Tables\Columns\Column;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

it('registers the essential inventory widgets including the expiry work queue', function (): void {
    $widgets = new ReflectionMethod(InventoryDashboard::class, 'getDashboardWidgets')->invoke(new InventoryDashboard);

    expect($widgets)->toBe([
        InventoryKeyMetrics::class,
        [InventoryMovementsTrend::class, InventoryStockValue::class],
        [InventoryLowStock::class, InventoryExpiringLots::class],
        InventoryRecentMovements::class,
    ]);
});

it('gives the movements chart the full row when stock value pricing is hidden', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo([InventoryPermission::StockView->value, InventoryPermission::MovementView->value]);
    $this->actingAs($user);

    $resolved = new ReflectionMethod(InventoryDashboard::class, 'resolveDashboardWidgets')->invoke(new InventoryDashboard);

    expect($resolved[1])->toBeInstanceOf(WidgetConfiguration::class)
        ->and($resolved[1]->widget)->toBe(InventoryMovementsTrend::class)
        ->and($resolved[1]->getProperties())->toBe(['spansFullWidth' => true]);
});

it('renders for a viewer with stock view access, with a warehouse filter', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(InventoryPermission::StockView->value);

    $warehouse = Warehouse::factory()->create(['name' => 'Dubai Main']);

    $this->actingAs($user)
        ->get(InventoryDashboard::getUrl())
        ->assertOk()
        ->assertSeeText(__('admin.resources.inventory_dashboard'));

    Livewire::test(InventoryDashboard::class)
        ->set('filters.warehouseId', $warehouse->id)
        ->assertSet('filters.warehouseId', $warehouse->id);
});

it('sorts the grouped expiring lot queue by an aggregate expiry date', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(InventoryPermission::StockView->value);

    $later = InventoryLot::factory()->create(['expires_at' => today()->addDays(15)]);
    $earlier = InventoryLot::factory()->create(['expires_at' => today()->addDays(3)]);
    $excluded = InventoryLot::factory()->create(['expires_at' => today()->addYear()]);

    $component = Livewire::actingAs($user)->test(InventoryExpiringLots::class);
    $widget = $component->instance();
    $sql = $widget->getTable()->getQuery()->toSql();

    expect($sql)->toContain('expiry_sort')
        ->and($sql)->not->toContain('order by (select');

    $lotIds = collect($widget->getTableRecords()->items())
        ->pluck('inventory_lot_id')
        ->map(static fn (mixed $id): int => (int) $id)
        ->all();

    expect($lotIds)->toBe([$earlier->id, $later->id])
        ->not->toContain($excluded->id);
});

/**
 * Seeds a lot without the factory's default warehouse balance, then one
 * balance row per [condition => on-hand quantity] pair in the warehouse.
 *
 * @param  array<string, mixed>  $attributes
 * @param  array<string, string>  $balances
 */
function expiringLotWithBalances(Warehouse $warehouse, array $attributes, array $balances = [StockCondition::Saleable->value => '10']): InventoryLot
{
    $lot = InventoryLot::factory()->canonical()->create($attributes);

    foreach ($balances as $condition => $onHand) {
        expiringLotBalance($lot, $warehouse, StockCondition::from($condition), $onHand);
    }

    return $lot;
}

function expiringLotBalance(InventoryLot $lot, Warehouse $warehouse, StockCondition $condition, string $onHand): void
{
    InventoryLotBalance::query()->forceCreate([
        'inventory_lot_id' => $lot->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => $condition,
        'on_hand_base_quantity' => $onHand,
        'reserved_base_quantity' => '0',
    ]);
}

/**
 * @return list<array{lot: int, warehouse: int, quantity: float}>
 */
function expiringLotRows(Testable $component): array
{
    return collect($component->instance()->getTableRecords()->items())
        ->map(static fn (InventoryLotBalance $row): array => [
            'lot' => (int) $row->inventory_lot_id,
            'warehouse' => (int) $row->warehouse_id,
            'quantity' => (float) $row->on_hand_base_quantity,
        ])
        ->all();
}

it('lists lots inside the notice window by earliest expiry and skips later or undated lots', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(InventoryPermission::StockView->value);

    $warehouse = Warehouse::factory()->create();

    $later = expiringLotWithBalances($warehouse, ['lot_number' => 'LOT-LATER', 'expires_at' => today()->addDays(40)]);
    $earlier = expiringLotWithBalances($warehouse, ['lot_number' => 'LOT-EARLIER', 'expires_at' => today()->addDays(5)]);
    $outsideWindow = expiringLotWithBalances($warehouse, ['lot_number' => 'LOT-OUTSIDE', 'expires_at' => today()->addDays(InventorySetting::expiryWindows()['notice'] + 30)]);
    $undated = expiringLotWithBalances($warehouse, ['lot_number' => 'LOT-UNDATED', 'expires_at' => null]);

    $component = Livewire::actingAs($user)->test(InventoryExpiringLots::class);

    expect(array_column(expiringLotRows($component), 'lot'))->toBe([$earlier->id, $later->id])
        ->not->toContain($outsideWindow->id, $undated->id);

    $component->assertSeeInOrder(['LOT-EARLIER', 'LOT-LATER'])
        ->assertDontSee(['LOT-OUTSIDE', 'LOT-UNDATED']);
});

it('sums positive balances per lot and warehouse and drops lots without positive stock', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(InventoryPermission::StockView->value);

    $warehouse = Warehouse::factory()->create();

    $summed = expiringLotWithBalances($warehouse, ['expires_at' => today()->addDays(10)], [
        StockCondition::Saleable->value => '10',
        StockCondition::Quarantine->value => '5',
        StockCondition::Damaged->value => '0',
    ]);
    $depleted = expiringLotWithBalances($warehouse, ['expires_at' => today()->addDays(11)], [
        StockCondition::Saleable->value => '0',
        StockCondition::Quarantine->value => '0',
    ]);

    $rows = expiringLotRows(Livewire::actingAs($user)->test(InventoryExpiringLots::class));

    expect($rows)->toBe([['lot' => $summed->id, 'warehouse' => $warehouse->id, 'quantity' => 15.0]])
        ->and(array_column($rows, 'lot'))->not->toContain($depleted->id);
});

it('shows one row per warehouse when the same lot is stocked in two warehouses', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(InventoryPermission::StockView->value);
    [$first, $second] = [Warehouse::factory()->create(), Warehouse::factory()->create()];

    $lot = expiringLotWithBalances($first, ['expires_at' => today()->addDays(10)], [StockCondition::Saleable->value => '4']);
    expiringLotBalance($lot, $second, StockCondition::Saleable, '6');

    $rows = expiringLotRows(Livewire::actingAs($user)->test(InventoryExpiringLots::class));

    expect($rows)->toBe([
        ['lot' => $lot->id, 'warehouse' => $first->id, 'quantity' => 4.0],
        ['lot' => $lot->id, 'warehouse' => $second->id, 'quantity' => 6.0],
    ]);
});

it('scopes expiring lots to the dashboard warehouse filter and shows the empty state without matches', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(InventoryPermission::StockView->value);
    [$stocked, $other, $empty] = [Warehouse::factory()->create(), Warehouse::factory()->create(), Warehouse::factory()->create()];

    $lot = expiringLotWithBalances($stocked, ['lot_number' => 'LOT-SHARED', 'expires_at' => today()->addDays(10)], [StockCondition::Saleable->value => '4']);
    expiringLotBalance($lot, $other, StockCondition::Saleable, '6');

    $scoped = Livewire::actingAs($user)->test(InventoryExpiringLots::class, ['pageFilters' => ['warehouseId' => $other->id]]);

    expect(expiringLotRows($scoped))->toBe([['lot' => $lot->id, 'warehouse' => $other->id, 'quantity' => 6.0]]);

    Livewire::actingAs($user)->test(InventoryExpiringLots::class, ['pageFilters' => ['warehouseId' => $empty->id]])
        ->assertSee(__('dashboards.empty'))
        ->assertDontSee('LOT-SHARED');
});

it('hides the expiring lots widget from a viewer without stock view', function (): void {
    $this->actingAs(User::factory()->create());

    expect(InventoryExpiringLots::canView())->toBeFalse();

    $viewer = User::factory()->create();
    $viewer->givePermissionTo(InventoryPermission::StockView->value);
    $this->actingAs($viewer);

    expect(InventoryExpiringLots::canView())->toBeTrue();
});

it('localizes the expiring lots heading and column labels in Arabic', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(InventoryPermission::StockView->value);

    app()->setLocale('ar');

    $table = Livewire::actingAs($user)->test(InventoryExpiringLots::class)->instance()->getTable();

    expect($table->getHeading())->toBe('الدفعات القريبة من الانتهاء')
        ->and(collect($table->getColumns())->map(static fn (Column $column): string => (string) $column->getLabel())->values()->all())->toBe([
            'المنتج',
            'التشغيلة',
            'المستودع',
            'الكمية',
            'تاريخ الانتهاء',
            'الأيام المتبقية',
        ]);
});
