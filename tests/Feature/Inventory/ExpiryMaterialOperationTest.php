<?php

declare(strict_types=1);

use App\Enums\InventoryAlertType;
use App\Enums\InventoryPermission;
use App\Models\AuditLog;
use App\Models\InventoryAlert;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function expiryOperationService(): InventoryOperationService
{
    return app(InventoryOperationService::class);
}

function expiringVariantWithStock(Warehouse $warehouse, float $quantity = 10.0): ProductVariant
{
    $variant = ProductVariant::factory()->expiryMaterial()->create();

    // Every balance stated explicitly so the fixture is internally coherent: the factory's
    // random reserved and damaged figures would otherwise contradict the available quantity.
    InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => $quantity,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => $quantity,
    ]);

    return $variant;
}

function actorWhoMayReleaseExpiredStock(): User
{
    (new InventoryPermissionSeeder)->run();
    $actor = User::factory()->create();
    $actor->givePermissionTo(InventoryPermission::ExpiredStockOverride->value);

    return $actor;
}

describe('receiving an expiry material', function (): void {
    it('creates the lot the receipt line describes', function (): void {
        $destination = Warehouse::factory()->create();
        $variant = ProductVariant::factory()->expiryMaterial()->create();
        $operation = InventoryOperation::factory()->receipt()->create(['destination_warehouse_id' => $destination->getKey()]);
        $operation->lines()->create([
            'product_variant_id' => $variant->getKey(),
            'quantity' => '5.000',
            'unit_id' => $variant->unit_id,
            'lot_number' => 'LOT-A',
            'expires_at' => today()->addMonths(6),
        ]);
        $actor = User::factory()->create();

        expiryOperationService()->markReady($operation);
        expiryOperationService()->complete($operation->refresh(), $actor);

        $lot = InventoryLot::query()->where('product_variant_id', $variant->getKey())->sole();

        expect($lot->lot_number)->toBe('LOT-A')
            ->and((float) $lot->on_hand_quantity)->toBe(5.0)
            ->and($lot->warehouse_id)->toBe($destination->getKey())
            ->and($lot->expires_at?->toDateString())->toBe(today()->addMonths(6)->toDateString())
            // The line is linked back to the lot it created, so the movement ledger can name it.
            ->and($operation->refresh()->lines()->value('inventory_lot_id'))->toBe($lot->id);
    });

    it('tops up one lot rather than fragmenting a batch received twice', function (): void {
        $destination = Warehouse::factory()->create();
        $variant = ProductVariant::factory()->expiryMaterial()->create();
        $expiry = today()->addMonths(3);
        $actor = User::factory()->create();

        foreach ([4, 6] as $quantity) {
            $operation = InventoryOperation::factory()->receipt()->create(['destination_warehouse_id' => $destination->getKey()]);
            $operation->lines()->create([
                'product_variant_id' => $variant->getKey(),
                'quantity' => $quantity,
                'unit_id' => $variant->unit_id,
                'lot_number' => 'LOT-SAME',
                'expires_at' => $expiry,
            ]);

            expiryOperationService()->markReady($operation);
            expiryOperationService()->complete($operation->refresh(), $actor);
        }

        $lot = InventoryLot::query()->where('product_variant_id', $variant->getKey())->sole();

        expect((float) $lot->on_hand_quantity)->toBe(10.0);
    });

    it('refuses a receipt line with no expiry date', function (): void {
        $destination = Warehouse::factory()->create();
        $variant = ProductVariant::factory()->expiryMaterial()->create();
        $operation = InventoryOperation::factory()->receipt()->create(['destination_warehouse_id' => $destination->getKey()]);
        $operation->lines()->create([
            'product_variant_id' => $variant->getKey(),
            'quantity' => '5.000',
            'unit_id' => $variant->unit_id,
        ]);

        expiryOperationService()->markReady($operation);
    })->throws(DomainException::class);

    it('refuses a receipt line whose expiry date has already passed', function (): void {
        $destination = Warehouse::factory()->create();
        $variant = ProductVariant::factory()->expiryMaterial()->create();
        $operation = InventoryOperation::factory()->receipt()->create(['destination_warehouse_id' => $destination->getKey()]);
        $operation->lines()->create([
            'product_variant_id' => $variant->getKey(),
            'quantity' => '5.000',
            'unit_id' => $variant->unit_id,
            'expires_at' => today()->subDay(),
        ]);

        expiryOperationService()->markReady($operation);
    })->throws(DomainException::class);
});

describe('delivering an expiry material', function (): void {
    it('draws the delivered quantity out of the named lot', function (): void {
        $source = Warehouse::factory()->create();
        $variant = expiringVariantWithStock($source);
        $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($source)->create([
            'on_hand_quantity' => '10.000',
            'reserved_quantity' => '0.000',
            'expires_at' => today()->addMonths(2),
        ]);
        $operation = InventoryOperation::factory()->delivery()->create(['source_warehouse_id' => $source->getKey()]);
        $operation->lines()->create([
            'product_variant_id' => $variant->getKey(),
            'quantity' => '4.000',
            'unit_id' => $variant->unit_id,
            'inventory_lot_id' => $lot->getKey(),
        ]);
        $actor = User::factory()->create();

        expiryOperationService()->markReady($operation);

        // Ready reserves the batch without moving it, mirroring the aggregate balance rule.
        expect((float) $lot->refresh()->reserved_quantity)->toBe(4.0)
            ->and((float) $lot->on_hand_quantity)->toBe(10.0);

        expiryOperationService()->complete($operation->refresh(), $actor);

        expect((float) $lot->refresh()->on_hand_quantity)->toBe(6.0)
            ->and((float) $lot->reserved_quantity)->toBe(0.0);
    });

    it('refuses an outbound line that names no lot', function (): void {
        $source = Warehouse::factory()->create();
        $variant = expiringVariantWithStock($source);
        InventoryLot::factory()->for($variant, 'productVariant')->for($source)->create([
            'on_hand_quantity' => '10.000',
            'expires_at' => today()->addMonths(2),
        ]);
        $operation = InventoryOperation::factory()->delivery()->create(['source_warehouse_id' => $source->getKey()]);
        $operation->lines()->create([
            'product_variant_id' => $variant->getKey(),
            'quantity' => '4.000',
            'unit_id' => $variant->unit_id,
        ]);

        expiryOperationService()->markReady($operation);
    })->throws(DomainException::class);

    it('refuses a lot belonging to another warehouse', function (): void {
        $source = Warehouse::factory()->create();
        $elsewhere = Warehouse::factory()->create();
        $variant = expiringVariantWithStock($source);
        $foreignLot = InventoryLot::factory()->for($variant, 'productVariant')->for($elsewhere)->create([
            'on_hand_quantity' => '10.000',
            'expires_at' => today()->addMonths(2),
        ]);
        $operation = InventoryOperation::factory()->delivery()->create(['source_warehouse_id' => $source->getKey()]);
        $operation->lines()->create([
            'product_variant_id' => $variant->getKey(),
            'quantity' => '4.000',
            'unit_id' => $variant->unit_id,
            'inventory_lot_id' => $foreignLot->getKey(),
        ]);

        expiryOperationService()->markReady($operation);
    })->throws(DomainException::class);

    it('refuses a lot that does not hold enough available quantity', function (): void {
        $source = Warehouse::factory()->create();
        $variant = expiringVariantWithStock($source, 20.0);
        $thinLot = InventoryLot::factory()->for($variant, 'productVariant')->for($source)->create([
            'on_hand_quantity' => '2.000',
            'expires_at' => today()->addMonths(2),
        ]);
        $operation = InventoryOperation::factory()->delivery()->create(['source_warehouse_id' => $source->getKey()]);
        $operation->lines()->create([
            'product_variant_id' => $variant->getKey(),
            'quantity' => '10.000',
            'unit_id' => $variant->unit_id,
            'inventory_lot_id' => $thinLot->getKey(),
        ]);

        expiryOperationService()->markReady($operation);
    })->throws(DomainException::class);

    it('returns the reservation to the lot when the operation is cancelled', function (): void {
        $source = Warehouse::factory()->create();
        $variant = expiringVariantWithStock($source);
        $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($source)->create([
            'on_hand_quantity' => '10.000',
            'reserved_quantity' => '0.000',
            'expires_at' => today()->addMonths(2),
        ]);
        $operation = InventoryOperation::factory()->delivery()->create(['source_warehouse_id' => $source->getKey()]);
        $operation->lines()->create([
            'product_variant_id' => $variant->getKey(),
            'quantity' => '4.000',
            'unit_id' => $variant->unit_id,
            'inventory_lot_id' => $lot->getKey(),
        ]);
        $actor = User::factory()->create();

        expiryOperationService()->markReady($operation);
        expiryOperationService()->cancel($operation->refresh(), $actor, 'Customer withdrew the order');

        expect((float) $lot->refresh()->reserved_quantity)->toBe(0.0)
            ->and((float) $lot->on_hand_quantity)->toBe(10.0);
    });
});

describe('the expired-stock block', function (): void {
    it('refuses to release an expired lot without the override permission', function (): void {
        $source = Warehouse::factory()->create();
        $variant = expiringVariantWithStock($source);
        $expiredLot = InventoryLot::factory()->for($variant, 'productVariant')->for($source)->create([
            'on_hand_quantity' => '10.000',
            'expires_at' => today()->subWeek(),
        ]);
        $operation = InventoryOperation::factory()->delivery()->create(['source_warehouse_id' => $source->getKey()]);
        $operation->lines()->create([
            'product_variant_id' => $variant->getKey(),
            'quantity' => '4.000',
            'unit_id' => $variant->unit_id,
            'inventory_lot_id' => $expiredLot->getKey(),
        ]);

        expiryOperationService()->markReady($operation, User::factory()->create());
    })->throws(DomainException::class);

    it('refuses an expired lot for a system-initiated transition with no actor at all', function (): void {
        $source = Warehouse::factory()->create();
        $variant = expiringVariantWithStock($source);
        $expiredLot = InventoryLot::factory()->for($variant, 'productVariant')->for($source)->create([
            'on_hand_quantity' => '10.000',
            'expires_at' => today()->subWeek(),
        ]);
        $operation = InventoryOperation::factory()->delivery()->create(['source_warehouse_id' => $source->getKey()]);
        $operation->lines()->create([
            'product_variant_id' => $variant->getKey(),
            'quantity' => '4.000',
            'unit_id' => $variant->unit_id,
            'inventory_lot_id' => $expiredLot->getKey(),
        ]);

        expiryOperationService()->markReady($operation);
    })->throws(DomainException::class);

    it('leaves the balance untouched when the block fires', function (): void {
        $source = Warehouse::factory()->create();
        $variant = expiringVariantWithStock($source);
        $expiredLot = InventoryLot::factory()->for($variant, 'productVariant')->for($source)->create([
            'on_hand_quantity' => '10.000',
            'expires_at' => today()->subWeek(),
        ]);
        $operation = InventoryOperation::factory()->delivery()->create(['source_warehouse_id' => $source->getKey()]);
        $operation->lines()->create([
            'product_variant_id' => $variant->getKey(),
            'quantity' => '4.000',
            'unit_id' => $variant->unit_id,
            'inventory_lot_id' => $expiredLot->getKey(),
        ]);

        try {
            expiryOperationService()->markReady($operation, User::factory()->create());
        } catch (DomainException) {
            // Expected — the point is what did *not* happen to the balances.
        }

        $stock = InventoryStock::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('warehouse_id', $source->getKey())
            ->sole();

        expect((float) $stock->on_hand_quantity)->toBe(10.0)
            ->and((float) $stock->reserved_quantity)->toBe(0.0)
            ->and((float) $expiredLot->refresh()->on_hand_quantity)->toBe(10.0)
            ->and((float) $expiredLot->reserved_quantity)->toBe(0.0);
    });

    it('lets an authorised actor release expired stock and records that they did', function (): void {
        $source = Warehouse::factory()->create();
        $variant = expiringVariantWithStock($source);
        $expiredLot = InventoryLot::factory()->for($variant, 'productVariant')->for($source)->create([
            'on_hand_quantity' => '10.000',
            'reserved_quantity' => '0.000',
            'expires_at' => today()->subWeek(),
        ]);
        $operation = InventoryOperation::factory()->delivery()->create(['source_warehouse_id' => $source->getKey()]);
        $operation->lines()->create([
            'product_variant_id' => $variant->getKey(),
            'quantity' => '4.000',
            'unit_id' => $variant->unit_id,
            'inventory_lot_id' => $expiredLot->getKey(),
        ]);
        $actor = actorWhoMayReleaseExpiredStock();

        expiryOperationService()->markReady($operation, $actor);
        expiryOperationService()->complete($operation->refresh(), $actor);

        expect((float) $expiredLot->refresh()->on_hand_quantity)->toBe(6.0)
            ->and(InventoryAlert::query()
                ->where('type', InventoryAlertType::ExpiredStockReleased->value)
                ->where('subject_id', $expiredLot->id)
                ->whereNull('resolved_at')
                ->exists())->toBeTrue()
            ->and(AuditLog::query()
                ->where('action', 'inventory.lot.expired_stock_released')
                ->where('actor_user_id', $actor->getKey())
                ->exists())->toBeTrue();
    });
});

it('restores the lot when an in-transit transfer is cancelled', function (): void {
    $source = Warehouse::factory()->create();
    $destination = Warehouse::factory()->create();
    $variant = expiringVariantWithStock($source);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($source)->create([
        'on_hand_quantity' => '10.000',
        'reserved_quantity' => '0.000',
        'expires_at' => today()->addMonths(2),
    ]);
    $operation = InventoryOperation::factory()->internalTransfer()->create([
        'source_warehouse_id' => $source->getKey(),
        'destination_warehouse_id' => $destination->getKey(),
    ]);
    $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '4.000',
        'unit_id' => $variant->unit_id,
        'inventory_lot_id' => $lot->getKey(),
    ]);
    $actor = User::factory()->create();

    expiryOperationService()->markReady($operation);
    expiryOperationService()->dispatch($operation->refresh(), $actor);

    expect((float) $lot->refresh()->on_hand_quantity)->toBe(6.0);

    expiryOperationService()->cancel($operation->refresh(), $actor, 'Recalled in transit');

    // Cancelling must leave no balance changed — the lot breakdown included.
    expect((float) $lot->refresh()->on_hand_quantity)->toBe(10.0);
});

it('leaves grains and machines free of any lot handling', function (): void {
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $operation = InventoryOperation::factory()->receipt()->create(['destination_warehouse_id' => $destination->getKey()]);
    $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '12.500',
        'unit_id' => $variant->unit_id,
    ]);
    $actor = User::factory()->create();

    expiryOperationService()->markReady($operation);
    expiryOperationService()->complete($operation->refresh(), $actor);

    expect(InventoryLot::query()->count())->toBe(0)
        ->and((float) InventoryStock::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('warehouse_id', $destination->getKey())
            ->value('on_hand_quantity'))->toBe(12.5);
});
