<?php

declare(strict_types=1);

use App\Enums\ReservationStatus;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryBalanceService;
use App\Services\Inventory\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('preserves the balance equation across every stock operation', function (): void {
    $service = app(InventoryBalanceService::class);
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $stock = InventoryStock::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'on_hand_quantity' => 10,
        'reserved_quantity' => 2,
        'damaged_quantity' => 1,
        'available_quantity' => 7,
    ]);

    $stock = $service->receive($variant, $warehouse->getKey(), 5);
    expectBalance($stock, [15, 2, 1, 12]);

    $stock = $service->reserve($stock, 3);
    expectBalance($stock, [15, 5, 1, 9]);

    $stock = $service->damage($stock, 4);
    expectBalance($stock, [15, 5, 5, 5]);

    $stock = $service->recoverDamage($stock, 2);
    expectBalance($stock, [15, 5, 3, 7]);

    $stock = $service->transferOut($variant, $warehouse->getKey(), 3);
    expectBalance($stock, [12, 5, 3, 4]);

    $stock = $service->adjustTo($variant, $warehouse->getKey(), 8);
    expectBalance($stock, [8, 5, 3, 0]);

    $stock = $service->releaseReservation($stock, 2);
    expectBalance($stock, [8, 3, 3, 2]);

    $stock = $service->disposeDamage($stock, 3);
    expectBalance($stock, [5, 3, 0, 2]);
});

it('creates missing balances only for inbound and absolute-count operations', function (): void {
    $service = app(InventoryBalanceService::class);
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();

    expect(fn () => $service->transferOut($variant, $warehouse->getKey(), 1))
        ->toThrow(DomainException::class, __('admin.inventory.balance.errors.missing_stock'));

    $received = $service->receive($variant, $warehouse->getKey(), 2.5);
    expectBalance($received, [2.5, 0, 0, 2.5]);

    $otherWarehouse = Warehouse::factory()->create();
    $adjusted = $service->adjustTo($variant, $otherWarehouse->getKey(), 4);
    expectBalance($adjusted, [4, 0, 0, 4]);
});

it('rejects invalid amounts and protected-balance violations without partial writes', function (): void {
    $service = app(InventoryBalanceService::class);
    $stock = InventoryStock::factory()->create([
        'on_hand_quantity' => 10,
        'reserved_quantity' => 4,
        'damaged_quantity' => 3,
        'available_quantity' => 3,
    ]);
    $variant = $stock->productVariant;
    $warehouseId = $stock->warehouse_id;

    foreach ([
        fn () => $service->receive($variant, $warehouseId, 0),
        fn () => $service->reserve($stock, 4),
        fn () => $service->damage($stock, 4),
        fn () => $service->transferOut($variant, $warehouseId, 4),
        fn () => $service->recoverDamage($stock, 4),
        fn () => $service->disposeDamage($stock, 4),
        fn () => $service->adjustTo($variant, $warehouseId, 6),
    ] as $operation) {
        expect($operation)->toThrow(DomainException::class);
        expectBalance($stock->fresh(), [10, 4, 3, 3]);
    }
});

it('keeps direct stock-field assignment out of inventory production services', function (): void {
    $violations = collect(glob(app_path('Services/Inventory/*.php')) ?: [])
        ->reject(fn (string $path): bool => str_ends_with($path, 'InventoryBalanceService.php'))
        ->filter(function (string $path): bool {
            $source = file_get_contents($path);

            return is_string($source)
                && preg_match('/->(?:on_hand_quantity|reserved_quantity|damaged_quantity|available_quantity)\s*=/', $source) === 1;
        })
        ->values()
        ->all();

    expect($violations)->toBe([]);
});

it('releases reservations through the shared balance service', function (): void {
    $actor = User::factory()->create();
    $stock = InventoryStock::factory()->create([
        'on_hand_quantity' => 10,
        'reserved_quantity' => 3,
        'damaged_quantity' => 1,
        'available_quantity' => 6,
    ]);
    $reservation = StockReservation::factory()->create([
        'product_variant_id' => $stock->product_variant_id,
        'warehouse_id' => $stock->warehouse_id,
        'quantity' => 2,
        'source_type' => 'test',
        'source_id' => 1,
        'expires_at' => now()->addHour(),
        'status' => ReservationStatus::Active,
    ]);

    app(ReservationService::class)->release($reservation, $actor);

    expectBalance($stock->fresh(), [10, 1, 1, 8]);
    expect($reservation->fresh()->status)->toBe(ReservationStatus::Released);
});

/** @param array{0: float, 1: float, 2: float, 3: float} $expected */
function expectBalance(InventoryStock $stock, array $expected): void
{
    [$onHand, $reserved, $damaged, $available] = $expected;

    expect((float) $stock->on_hand_quantity)->toBe((float) $onHand)
        ->and((float) $stock->reserved_quantity)->toBe((float) $reserved)
        ->and((float) $stock->damaged_quantity)->toBe((float) $damaged)
        ->and((float) $stock->available_quantity)->toBe((float) $available)
        ->and((float) $stock->available_quantity)
        ->toBe((float) $stock->on_hand_quantity - (float) $stock->reserved_quantity - (float) $stock->damaged_quantity);
}
