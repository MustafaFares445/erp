<?php

declare(strict_types=1);

use App\Enums\InventoryDiagnosticSeverity;
use App\Enums\InventoryPermission;
use App\Enums\OperationStage;
use App\Enums\ReservationStatus;
use App\Enums\StockCondition;
use App\Filament\Resources\StockLevels\Pages\ViewStockLevel;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\User;
use App\Models\WarehouseReplenishmentPolicy;
use App\Services\Inventory\Diagnostics\StockLevelDiagnoser;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
    $manager = User::factory()->admin()->create();
    $manager->givePermissionTo(array_map(static fn (InventoryPermission $permission): string => $permission->value, InventoryPermission::cases()));
    $this->actingAs($manager);
});

/**
 * @param  array{saleable?: float, reserved?: float, quarantine?: float, damaged?: float}  $balances
 */
function stockWithBalances(array $balances): InventoryStock
{
    $saleable = $balances['saleable'] ?? 0.0;
    $reserved = $balances['reserved'] ?? 0.0;
    $quarantine = $balances['quarantine'] ?? 0.0;
    $damaged = $balances['damaged'] ?? 0.0;

    $stock = InventoryStock::factory()->create([
        'on_hand_quantity' => $saleable + $quarantine + $damaged,
        'reserved_quantity' => $reserved,
        'damaged_quantity' => $damaged,
        'available_quantity' => max(0.0, $saleable - $reserved),
    ]);

    foreach ([
        StockCondition::Saleable->value => [$saleable, $reserved],
        StockCondition::Quarantine->value => [$quarantine, 0.0],
        StockCondition::Damaged->value => [$damaged, 0.0],
    ] as $condition => [$onHand, $conditionReserved]) {
        InventoryConditionBalance::query()->forceCreate([
            'product_variant_id' => $stock->product_variant_id,
            'warehouse_id' => $stock->warehouse_id,
            'stock_condition' => $condition,
            'on_hand_base_quantity' => $onHand,
            'reserved_base_quantity' => $conditionReserved,
        ]);
    }

    return $stock;
}

function stockPolicy(InventoryStock $stock, float $minimum, float $maximum, bool $active = true): void
{
    WarehouseReplenishmentPolicy::query()->updateOrCreate(
        ['warehouse_id' => $stock->warehouse_id, 'product_variant_id' => $stock->product_variant_id],
        ['min_quantity' => $minimum, 'max_quantity' => $maximum, 'is_active' => $active],
    );
}

it('reports nothing beyond the listed checks for a balance above its minimum', function (): void {
    $stock = stockWithBalances(['saleable' => 40]);
    stockPolicy($stock, 10, 50);

    $diagnosis = app(StockLevelDiagnoser::class)->diagnose($stock);

    expect($diagnosis->findings)->toBe([])
        ->and($diagnosis->severity())->toBe(InventoryDiagnosticSeverity::Clear)
        ->and($diagnosis->checks)->not->toBeEmpty()
        ->and($diagnosis->limits)->toContain(__('inventory_view_ux.limits.recorded_balances'));
});

it('explains reserved stock and when nothing is left available', function (): void {
    $stock = stockWithBalances(['saleable' => 5, 'reserved' => 5]);
    stockPolicy($stock, 1, 20);
    InventoryReservation::factory()->create([
        'product_variant_id' => $stock->product_variant_id,
        'warehouse_id' => $stock->warehouse_id,
        'base_quantity' => 5,
    ]);

    $diagnosis = app(StockLevelDiagnoser::class)->diagnose($stock);

    expect($diagnosis->codes())->toContain('stock.none_available', 'stock.reserved')
        ->and($diagnosis->find('stock.none_available')?->severity)->toBe(InventoryDiagnosticSeverity::Warning)
        ->and($diagnosis->find('stock.reserved')?->facts)->toHaveCount(1);
});

it('flags reserved quantity above saleable on-hand as a blocker', function (): void {
    $stock = stockWithBalances(['saleable' => 3, 'reserved' => 5]);

    $diagnosis = app(StockLevelDiagnoser::class)->diagnose($stock);

    expect($diagnosis->find('stock.inconsistent')?->severity)->toBe(InventoryDiagnosticSeverity::Blocker)
        ->and($diagnosis->severity())->toBe(InventoryDiagnosticSeverity::Blocker);
});

it('explains quarantined and damaged stock with a permission-aware next step', function (): void {
    $stock = stockWithBalances(['saleable' => 3, 'quarantine' => 2, 'damaged' => 1]);

    $diagnosis = app(StockLevelDiagnoser::class)->diagnose($stock);

    expect($diagnosis->codes())->toContain('stock.quarantine', 'stock.damaged')
        ->and($diagnosis->find('stock.quarantine')?->action?->allowed)->toBeTrue()
        ->and($diagnosis->find('stock.quarantine')?->action?->url)->not->toBeNull();

    $viewer = User::factory()->admin()->create();
    $viewer->givePermissionTo(InventoryPermission::StockView->value);
    $this->actingAs($viewer);

    $restricted = app(StockLevelDiagnoser::class)->diagnose($stock)->find('stock.quarantine');

    expect($restricted?->action?->allowed)->toBeFalse()
        ->and($restricted?->action?->url)->toBeNull()
        ->and($restricted?->action?->deniedReason)->not->toBeNull();
});

it('distinguishes a breached minimum from one covered by incoming transfers', function (): void {
    $stock = stockWithBalances(['saleable' => 4]);
    stockPolicy($stock, 10, 30);

    expect(app(StockLevelDiagnoser::class)->diagnose($stock)->find('stock.below_minimum')?->severity)
        ->toBe(InventoryDiagnosticSeverity::Warning);

    $transfer = InventoryOperation::factory()->internalTransfer()->create([
        'stage' => OperationStage::InTransit,
        'destination_warehouse_id' => $stock->warehouse_id,
    ]);
    InventoryOperationLine::factory()->for($transfer, 'operation')->create([
        'product_variant_id' => $stock->product_variant_id,
        'quantity' => 20,
        'base_quantity' => 20,
        'dispatched_base_quantity' => 20,
        'received_base_quantity' => 0,
    ]);

    $diagnosis = app(StockLevelDiagnoser::class)->diagnose($stock->fresh());

    expect($diagnosis->codes())->toContain('stock.below_minimum_covered', 'stock.in_transit')
        ->not->toContain('stock.below_minimum')
        ->and($diagnosis->find('stock.below_minimum_covered')?->severity)->toBe(InventoryDiagnosticSeverity::Notice);
});

it('tells the user when no replenishment threshold can detect low stock', function (): void {
    $stock = stockWithBalances(['saleable' => 4]);

    expect(app(StockLevelDiagnoser::class)->diagnose($stock)->codes())->toContain('stock.no_policy');

    stockPolicy($stock, 2, 10, active: false);

    expect(app(StockLevelDiagnoser::class)->diagnose($stock)->codes())->toContain('stock.policy_inactive');
});

it('finds expired lots and overdue reservations that still hold stock', function (): void {
    $stock = stockWithBalances(['saleable' => 8, 'reserved' => 2]);
    stockPolicy($stock, 1, 20);
    $lot = InventoryLot::factory()->create([
        'product_variant_id' => $stock->product_variant_id,
        'warehouse_id' => null,
        'expires_at' => today()->subDays(3),
    ]);
    InventoryLotBalance::query()->forceCreate([
        'inventory_lot_id' => $lot->getKey(),
        'warehouse_id' => $stock->warehouse_id,
        'stock_condition' => StockCondition::Saleable->value,
        'on_hand_base_quantity' => 6,
        'reserved_base_quantity' => 0,
    ]);
    InventoryReservation::factory()->create([
        'product_variant_id' => $stock->product_variant_id,
        'warehouse_id' => $stock->warehouse_id,
        'base_quantity' => 2,
        'status' => ReservationStatus::Active,
        'expires_at' => now()->subHour(),
    ]);

    $diagnosis = app(StockLevelDiagnoser::class)->diagnose($stock);

    expect($diagnosis->codes())->toContain('stock.expired_lots', 'stock.reservation_overdue')
        ->and($diagnosis->find('stock.expired_lots')?->facts[0])->toContain((string) $lot->lot_number);
});

it('quantifies waiting deliveries against the available balance', function (): void {
    $stock = stockWithBalances(['saleable' => 2]);
    stockPolicy($stock, 1, 20);
    $delivery = InventoryOperation::factory()->delivery()->create([
        'stage' => OperationStage::Waiting,
        'source_warehouse_id' => $stock->warehouse_id,
    ]);
    InventoryOperationLine::factory()->for($delivery, 'operation')->create([
        'product_variant_id' => $stock->product_variant_id,
        'quantity' => 5,
        'base_quantity' => 5,
    ]);

    $finding = app(StockLevelDiagnoser::class)->diagnose($stock)->find('stock.waiting_deliveries');

    expect($finding?->severity)->toBe(InventoryDiagnosticSeverity::Warning)
        ->and($finding?->facts)->toHaveCount(2)
        ->and($finding?->action?->url)->not->toBeNull();
});

it('renders the operational health panel without changing the balance', function (): void {
    $stock = stockWithBalances(['saleable' => 5, 'quarantine' => 2]);
    $before = $stock->fresh()->only(['on_hand_quantity', 'reserved_quantity', 'available_quantity', 'updated_at']);

    Livewire::test(ViewStockLevel::class, ['record' => $stock->getKey()])
        ->assertOk()
        ->assertSee(__('inventory_view_ux.title'))
        ->assertSee(__('inventory_view_ux.metrics_title'))
        ->assertSeeHtml('data-finding="stock.quarantine"');

    expect($stock->fresh()->only(['on_hand_quantity', 'reserved_quantity', 'available_quantity', 'updated_at']))->toEqual($before)
        ->and(InventoryConditionBalance::query()->count())->toBe(3);
});
