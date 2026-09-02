<?php

declare(strict_types=1);

use App\Data\Inventory\StockDamageData;
use App\Enums\MovementType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\AuditLog;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Services\Inventory\InventoryDamageService;
use App\Services\Inventory\SerializedInventoryTimelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('damages recovers and disposes stock with movements and audits', function (): void {
    $service = app(InventoryDamageService::class);
    $actor = User::factory()->admin()->create();
    $stock = InventoryStock::factory()->create([
        'on_hand_quantity' => 10,
        'reserved_quantity' => 2,
        'damaged_quantity' => 0,
        'available_quantity' => 8,
    ]);
    $lot = InventoryLot::factory()
        ->for($stock->productVariant)
        ->for($stock->warehouse)
        ->create([
            'on_hand_quantity' => '10.000000',
            'reserved_quantity' => '2.000000',
            'expires_at' => null,
        ]);

    $stock = $service->damage($stock, new StockDamageData(3, 'Transit damage', null, $lot->getKey()), $actor);
    expectDamageBalance($stock, [10, 2, 3, 5]);

    expectConditionBalance($stock, StockCondition::Saleable, 7, 2);
    expectConditionBalance($stock, StockCondition::Damaged, 3, 0);

    $stock = $service->recover($stock, new StockDamageData(1, 'Repaired', null, $lot->getKey()), $actor);
    expectDamageBalance($stock, [10, 2, 2, 6]);
    expectConditionBalance($stock, StockCondition::Saleable, 8, 2);
    expectConditionBalance($stock, StockCondition::Damaged, 2, 0);

    $stock = $service->dispose($stock, new StockDamageData(2, 'Beyond repair', null, $lot->getKey()), $actor);
    expectDamageBalance($stock, [8, 2, 0, 6]);
    expectConditionBalance($stock, StockCondition::Saleable, 8, 2);
    expectConditionBalance($stock, StockCondition::Damaged, 0, 0);

    $movements = InventoryMovement::query()
        ->where('source_type', 'stock_damage')
        ->where('source_id', $stock->getKey())
        ->orderBy('id')
        ->get();

    expect($movements)->toHaveCount(3)
        ->and($movements->pluck('movement_type')->all())->toBe([
            MovementType::Damage,
            MovementType::DamageRecovery,
            MovementType::Disposal,
        ])
        ->and($movements->map(fn (InventoryMovement $movement): float => (float) $movement->quantity)->all())
        ->toBe([-3.0, 1.0, -2.0])
        ->and($movements->map(fn (InventoryMovement $movement): float => (float) $movement->transaction_quantity)->all())
        ->toBe([3.0, 1.0, 2.0])
        ->and($movements->every(fn (InventoryMovement $movement): bool => $movement->transaction_unit_id === $stock->productVariant->unit_id))
        ->toBeTrue()
        ->and($movements->every(fn (InventoryMovement $movement): bool => $movement->conversion_factor_snapshot === '1.000000'))
        ->toBeTrue()
        ->and($movements->map(fn (InventoryMovement $movement): float => (float) $movement->base_quantity_delta)->all())
        ->toBe([-3.0, 1.0, -2.0])
        ->and(AuditLog::query()->where('subject_type', InventoryStock::class)->count())->toBe(3);
});

it('tracks a serialized device through damage recovery and disposal', function (): void {
    $service = app(InventoryDamageService::class);
    $actor = User::factory()->admin()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $stock = InventoryStock::factory()->for($variant)->create([
        'on_hand_quantity' => 1,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 1,
    ]);
    $unit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $stock->product_variant_id,
        'warehouse_id' => $stock->warehouse_id,
        'status' => SerializedInventoryUnitStatus::Available,
    ]);
    $target = new StockDamageData(1, 'Device casing damaged', $unit->getKey());

    $stock = $service->damage($stock, $target, $actor);
    expect($unit->fresh()->status)->toBe(SerializedInventoryUnitStatus::Damaged)
        ->and($unit->fresh()->stock_condition)->toBe(StockCondition::Damaged);
    expectDamageBalance($stock, [1, 0, 1, 0]);

    $stock = $service->recover($stock, new StockDamageData(1, 'Device repaired', $unit->getKey()), $actor);
    expect($unit->fresh()->status)->toBe(SerializedInventoryUnitStatus::Available)
        ->and($unit->fresh()->stock_condition)->toBe(StockCondition::Saleable);
    expectDamageBalance($stock, [1, 0, 0, 1]);

    $stock = $service->damage($stock, $target, $actor);
    $stock = $service->dispose($stock, new StockDamageData(1, 'Device scrapped', $unit->getKey()), $actor);

    $events = app(SerializedInventoryTimelineService::class)->events($unit->fresh());

    expect($unit->fresh()->status)->toBe(SerializedInventoryUnitStatus::Disposed)
        ->and($unit->fresh()->stock_condition)->toBe(StockCondition::Disposed)
        ->and($unit->fresh()->warehouse_id)->toBeNull()
        ->and(InventoryMovement::query()->where('serialized_inventory_unit_id', $unit->getKey())->count())->toBe(4)
        ->and(array_column($events, 'type'))->toBe([
            MovementType::Damage->value,
            MovementType::DamageRecovery->value,
            MovementType::Damage->value,
            MovementType::Disposal->value,
        ]);
    expectDamageBalance($stock, [0, 0, 0, 0]);
});

it('requires and preserves a lot allocation for batch-tracked damage and disposal', function (): void {
    $actor = User::factory()->admin()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $stock = InventoryStock::factory()->for($variant)->create([
        'on_hand_quantity' => 5,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 5,
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->create([
        'warehouse_id' => $stock->warehouse_id,
        'on_hand_quantity' => '5.000000',
        'reserved_quantity' => '0.000000',
        'expires_at' => null,
    ]);
    $service = app(InventoryDamageService::class);

    expect(fn () => $service->damage($stock, new StockDamageData(1, 'Missing lot'), $actor))
        ->toThrow(DomainException::class, __('admin.inventory.lot.errors.required'));

    $stock = $service->damage($stock, new StockDamageData(2, 'Batch damaged', null, $lot->getKey()), $actor);

    $stock = $service->dispose($stock, new StockDamageData(1, 'Batch scrapped', null, $lot->getKey()), $actor);

    $saleableLot = InventoryLotBalance::query()
        ->where('inventory_lot_id', $lot->getKey())
        ->where('stock_condition', StockCondition::Saleable->value)
        ->sole();
    $damagedLot = InventoryLotBalance::query()
        ->where('inventory_lot_id', $lot->getKey())
        ->where('stock_condition', StockCondition::Damaged->value)
        ->sole();

    expect($stock->on_hand_quantity)->toBe('4.000000')
        ->and($saleableLot->on_hand_base_quantity)->toBe('3.000000')
        ->and($damagedLot->on_hand_base_quantity)->toBe('1.000000')
        ->and(InventoryMovement::query()->where('inventory_lot_id', $lot->getKey())->count())->toBe(2);
});

it('rejects damage to reserved stock and rolls back every side effect', function (): void {
    $actor = User::factory()->admin()->create();
    $stock = InventoryStock::factory()->create([
        'on_hand_quantity' => 5,
        'reserved_quantity' => 4,
        'damaged_quantity' => 0,
        'available_quantity' => 1,
    ]);
    $lot = InventoryLot::factory()
        ->for($stock->productVariant)
        ->for($stock->warehouse)
        ->create([
            'on_hand_quantity' => '5.000000',
            'reserved_quantity' => '4.000000',
            'expires_at' => null,
        ]);

    expect(fn () => app(InventoryDamageService::class)->damage(
        $stock,
        new StockDamageData(2, 'Invalid quarantine', null, $lot->getKey()),
        $actor,
    ))->toThrow(DomainException::class, __('admin.inventory.balance.errors.insufficient_available'));

    expectDamageBalance($stock->fresh(), [5, 4, 0, 1]);
    expect(InventoryMovement::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('validates the reason serialized quantity location variant and status', function (): void {
    $actor = User::factory()->admin()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $stock = InventoryStock::factory()->for($variant)->create([
        'on_hand_quantity' => 2,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 2,
    ]);
    $wrongUnit = SerializedInventoryUnit::factory()->create([
        'status' => SerializedInventoryUnitStatus::Available,
    ]);
    $service = app(InventoryDamageService::class);

    expect(fn () => $service->damage($stock, new StockDamageData(1, ' '), $actor))
        ->toThrow(DomainException::class, __('admin.inventory.damage.errors.reason_required'));
    expect(fn () => $service->damage($stock, new StockDamageData(2, 'Too many', $wrongUnit->getKey()), $actor))
        ->toThrow(DomainException::class, __('admin.inventory.damage.errors.serial_quantity'));
    expect(fn () => $service->damage($stock, new StockDamageData(1, 'Wrong device', $wrongUnit->getKey()), $actor))
        ->toThrow(DomainException::class, __('admin.inventory.damage.errors.invalid_serial'));

    expectDamageBalance($stock->fresh(), [2, 0, 0, 2]);
});

it('rejects non-positive quantities unsupported operations and unsaved balances', function (): void {
    $actor = User::factory()->admin()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $stock = InventoryStock::factory()->for($variant)->create([
        'on_hand_quantity' => 2,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 2,
    ]);
    $service = app(InventoryDamageService::class);

    expect(fn () => $service->damage($stock, new StockDamageData(0, 'Invalid quantity'), $actor))
        ->toThrow(DomainException::class, __('admin.inventory.balance.errors.invalid_quantity'));

    $execute = new ReflectionMethod($service, 'execute');
    expect(fn (): mixed => $execute->invoke(
        $service,
        $stock,
        new StockDamageData(1, 'Unsupported operation'),
        $actor,
        MovementType::Sale,
    ))->toThrow(UnhandledMatchError::class);

    $auditAction = new ReflectionMethod($service, 'auditAction');
    expect(fn (): mixed => $auditAction->invoke($service, MovementType::Sale))
        ->toThrow(LogicException::class, 'Unsupported damage audit operation.');

    expect(fn () => $service->damage(
        new InventoryStock,
        new StockDamageData(1, 'Unsaved stock'),
        $actor,
    ))->toThrow(LogicException::class, 'Inventory stocks must use integer identifiers.');
});

/** @param array{0: float, 1: float, 2: float, 3: float} $expected */
function expectDamageBalance(InventoryStock $stock, array $expected): void
{
    [$onHand, $reserved, $damaged, $available] = $expected;

    expect((float) $stock->on_hand_quantity)->toEqual($onHand)
        ->and((float) $stock->reserved_quantity)->toEqual($reserved)
        ->and((float) $stock->damaged_quantity)->toEqual($damaged)
        ->and((float) $stock->available_quantity)->toEqual($available);
}

function expectConditionBalance(
    InventoryStock $stock,
    StockCondition $condition,
    float $onHand,
    float $reserved,
): void {
    $balance = InventoryConditionBalance::query()
        ->where('product_variant_id', $stock->product_variant_id)
        ->where('warehouse_id', $stock->warehouse_id)
        ->where('stock_condition', $condition->value)
        ->sole();

    expect((float) $balance->on_hand_base_quantity)->toBe($onHand)
        ->and((float) $balance->reserved_base_quantity)->toBe($reserved);
}
