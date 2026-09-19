<?php

declare(strict_types=1);

use App\Enums\StockCondition;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\Logistics\OutboundAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allocates batch tracked demand across lots and stops when demand is satisfied', function (): void {
    $variant = ProductVariant::factory()->grain()->create();
    $warehouse = Warehouse::factory()->create();

    $lots = collect([
        ['number' => 'LOT-COV-A', 'quantity' => '2.000000'],
        ['number' => 'LOT-COV-B', 'quantity' => '3.000000'],
        ['number' => 'LOT-COV-C', 'quantity' => '4.000000'],
    ])->map(function (array $data) use ($variant, $warehouse): InventoryLot {
        $lot = InventoryLot::factory()
            ->for($variant, 'productVariant')
            ->for($warehouse)
            ->create([
                'lot_number' => $data['number'],
                'on_hand_quantity' => $data['quantity'],
                'reserved_quantity' => '0.000000',
                'expires_at' => null,
            ]);

        InventoryLotBalance::query()
            ->where('inventory_lot_id', $lot->getKey())
            ->where('warehouse_id', $warehouse->getKey())
            ->where('stock_condition', StockCondition::Saleable->value)
            ->update([
                'on_hand_base_quantity' => $data['quantity'],
                'reserved_base_quantity' => '0.000000',
            ]);

        return $lot;
    });

    $method = new ReflectionMethod(OutboundAvailabilityService::class, 'trackedAssignments');
    $assignments = $method->invoke(
        app(OutboundAvailabilityService::class),
        $variant->refresh(),
        (int) $warehouse->getKey(),
        4.0,
    );

    expect($assignments)->toHaveCount(2)
        ->and(array_sum(array_column($assignments, 'quantity')))->toBe(4.0)
        ->and(collect($assignments)->pluck('inventory_lot_id')->every(
            fn (int $lotId): bool => $lots->pluck('id')->contains($lotId),
        ))->toBeTrue();
});

it('returns no batch assignments when requested quantity is already satisfied', function (): void {
    $variant = ProductVariant::factory()->grain()->create();
    $warehouse = Warehouse::factory()->create();

    InventoryLot::factory()
        ->for($variant, 'productVariant')
        ->for($warehouse)
        ->create(['on_hand_quantity' => '1.000000']);

    $assignments = new ReflectionMethod(OutboundAvailabilityService::class, 'trackedAssignments')
        ->invoke(
            app(OutboundAvailabilityService::class),
            $variant->refresh(),
            (int) $warehouse->getKey(),
            0.0,
        );

    expect($assignments)->toBe([]);
});
