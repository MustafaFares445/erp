<?php

declare(strict_types=1);

use App\Data\Inventory\StockDamageData;
use App\Enums\MovementType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
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

    $stock = $service->damage($stock, new StockDamageData(3, 'Transit damage'), $actor);
    expectDamageBalance($stock, [10, 2, 3, 5]);

    $stock = $service->recover($stock, new StockDamageData(1, 'Repaired'), $actor);
    expectDamageBalance($stock, [10, 2, 2, 6]);

    $stock = $service->dispose($stock, new StockDamageData(2, 'Beyond repair'), $actor);
    expectDamageBalance($stock, [8, 2, 0, 6]);

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
        ->and(AuditLog::query()->where('entity_type', InventoryStock::class)->count())->toBe(3);
});

it('tracks a serialized device through damage recovery and disposal', function (): void {
    $service = app(InventoryDamageService::class);
    $actor = User::factory()->admin()->create();
    $stock = InventoryStock::factory()->create([
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
    expect($unit->fresh()->status)->toBe(SerializedInventoryUnitStatus::Damaged);
    expectDamageBalance($stock, [1, 0, 1, 0]);

    $stock = $service->recover($stock, new StockDamageData(1, 'Device repaired', $unit->getKey()), $actor);
    expect($unit->fresh()->status)->toBe(SerializedInventoryUnitStatus::Available);
    expectDamageBalance($stock, [1, 0, 0, 1]);

    $stock = $service->damage($stock, $target, $actor);
    $stock = $service->dispose($stock, new StockDamageData(1, 'Device scrapped', $unit->getKey()), $actor);
    $events = app(SerializedInventoryTimelineService::class)->events($unit->fresh());

    expect($unit->fresh()->status)->toBe(SerializedInventoryUnitStatus::Disposed)
        ->and($unit->fresh()->warehouse_id)->toBeNull()
        ->and(InventoryMovement::query()->where('serialized_inventory_unit_id', $unit->getKey())->count())->toBe(4)
        ->and(array_column($events, 'type'))->toBe([
            MovementType::Receipt->value,
            MovementType::Damage->value,
            MovementType::DamageRecovery->value,
            MovementType::Damage->value,
            MovementType::Disposal->value,
        ]);
    expectDamageBalance($stock, [0, 0, 0, 0]);
});

it('rejects damage to reserved stock and rolls back every side effect', function (): void {
    $actor = User::factory()->admin()->create();
    $stock = InventoryStock::factory()->create([
        'on_hand_quantity' => 5,
        'reserved_quantity' => 4,
        'damaged_quantity' => 0,
        'available_quantity' => 1,
    ]);

    expect(fn () => app(InventoryDamageService::class)->damage(
        $stock,
        new StockDamageData(2, 'Invalid quarantine'),
        $actor,
    ))->toThrow(DomainException::class, __('admin.inventory.balance.errors.insufficient_available'));

    expectDamageBalance($stock->fresh(), [5, 4, 0, 1]);
    expect(InventoryMovement::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('validates the reason serialized quantity location variant and status', function (): void {
    $actor = User::factory()->admin()->create();
    $stock = InventoryStock::factory()->create([
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

/** @param array{0: float, 1: float, 2: float, 3: float} $expected */
function expectDamageBalance(InventoryStock $stock, array $expected): void
{
    [$onHand, $reserved, $damaged, $available] = $expected;

    expect((float) $stock->on_hand_quantity)->toBe((float) $onHand)
        ->and((float) $stock->reserved_quantity)->toBe((float) $reserved)
        ->and((float) $stock->damaged_quantity)->toBe((float) $damaged)
        ->and((float) $stock->available_quantity)->toBe((float) $available);
}
