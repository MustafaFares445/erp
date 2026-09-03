<?php

declare(strict_types=1);

use App\Data\Inventory\QuarantineDispositionData;
use App\Enums\ConditionChangeReason;
use App\Enums\InventoryConditionChangeStatus;
use App\Enums\InventoryPermission;
use App\Enums\MovementType;
use App\Enums\QuarantineDisposition;
use App\Enums\StockCondition;
use App\Exceptions\Domain\IllegalStatusTransition;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryConditionChange;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryConditionChangeService;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

function quarantineActor(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo([
        InventoryPermission::ConditionChangeView->value,
        InventoryPermission::ConditionChangeCreate->value,
        InventoryPermission::ConditionChangePost->value,
        InventoryPermission::ConditionChangeCancel->value,
    ]);

    return $actor;
}

/** @return array{0:ProductVariant,1:Warehouse,2:InventoryStock} */
function quarantinedInventory(string $quantity = '10.000000'): array
{
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $stock = InventoryStock::factory()
        ->for($variant, 'productVariant')
        ->for($warehouse)
        ->create([
            'on_hand_quantity' => $quantity,
            'reserved_quantity' => '0.000000',
            'damaged_quantity' => '0.000000',
            'available_quantity' => '0.000000',
        ]);

    foreach ([
        StockCondition::Saleable => '0.000000',
        StockCondition::Quarantine => $quantity,
        StockCondition::Damaged => '0.000000',
    ] as $condition => $onHand) {
        InventoryConditionBalance::query()->updateOrCreate([
            'product_variant_id' => $variant->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'stock_condition' => $condition->value,
        ], [
            'on_hand_base_quantity' => $onHand,
            'reserved_base_quantity' => '0.000000',
        ]);
    }

    return [$variant, $warehouse, $stock];
}

function draftDisposition(
    InventoryConditionChangeService $service,
    User $actor,
    ProductVariant $variant,
    Warehouse $warehouse,
    QuarantineDisposition $disposition,
    string $quantity = '10.000000',
): InventoryConditionChange {
    return $service->draftQuarantineDisposition(
        new QuarantineDispositionData(
            productVariantId: (int) $variant->getKey(),
            warehouseId: (int) $warehouse->getKey(),
            inventoryLotId: null,
            serializedInventoryUnitId: null,
            baseQuantity: $quantity,
            disposition: $disposition,
            reasonCategory: ConditionChangeReason::QualityInspectionPassed,
            reason: 'QA disposition test',
        ),
        $actor,
    );
}

it('releases quarantined stock to saleable through the canonical posting path', function (): void {
    [$variant, $warehouse, $stock] = quarantinedInventory();
    $actor = quarantineActor();
    $service = app(InventoryConditionChangeService::class);
    $change = draftDisposition($service, $actor, $variant, $warehouse, QuarantineDisposition::ReleaseToSaleable);

    $posted = $service->post($change, $actor);

    expect($posted->status)->toBe(InventoryConditionChangeStatus::Posted)
        ->and($posted->inventory_movement_id)->not->toBeNull()
        ->and($stock->fresh()?->on_hand_quantity)->toBe('10.000000')
        ->and($stock->fresh()?->available_quantity)->toBe('10.000000')
        ->and(InventoryConditionBalance::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('warehouse_id', $warehouse->getKey())
            ->where('stock_condition', StockCondition::Quarantine->value)
            ->value('on_hand_base_quantity'))->toBe('0.000000')
        ->and(InventoryConditionBalance::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('warehouse_id', $warehouse->getKey())
            ->where('stock_condition', StockCondition::Saleable->value)
            ->value('on_hand_base_quantity'))->toBe('10.000000')
        ->and(InventoryMovement::query()
            ->where('source_type', 'inventory_condition_change')
            ->where('source_id', $change->getKey())
            ->count())->toBe(1);
});

it('downgrades quarantined stock to damaged without changing aggregate on hand', function (): void {
    [$variant, $warehouse, $stock] = quarantinedInventory();
    $actor = quarantineActor();
    $service = app(InventoryConditionChangeService::class);
    $change = draftDisposition($service, $actor, $variant, $warehouse, QuarantineDisposition::DowngradeToDamaged);

    $service->post($change, $actor);

    expect($stock->fresh()?->on_hand_quantity)->toBe('10.000000')
        ->and($stock->fresh()?->damaged_quantity)->toBe('10.000000')
        ->and($stock->fresh()?->available_quantity)->toBe('0.000000');
});

it('disposes quarantined stock and removes it from aggregate on hand', function (): void {
    [$variant, $warehouse, $stock] = quarantinedInventory();
    $actor = quarantineActor();
    $service = app(InventoryConditionChangeService::class);
    $change = draftDisposition($service, $actor, $variant, $warehouse, QuarantineDisposition::Dispose);

    $service->post($change, $actor);

    expect($stock->fresh()?->on_hand_quantity)->toBe('0.000000')
        ->and($stock->fresh()?->available_quantity)->toBe('0.000000');
});

it('refuses to post the same quarantine disposition twice', function (): void {
    [$variant, $warehouse] = quarantinedInventory();
    $actor = quarantineActor();
    $service = app(InventoryConditionChangeService::class);
    $change = draftDisposition($service, $actor, $variant, $warehouse, QuarantineDisposition::ReleaseToSaleable);

    $service->post($change, $actor);

    expect(fn () => $service->post($change, $actor))
        ->toThrow(IllegalStatusTransition::class)
        ->and(InventoryMovement::query()
            ->where('source_type', 'inventory_condition_change')
            ->where('source_id', $change->getKey())
            ->count())->toBe(1);
});

it('cancels a draft without writing any movement', function (): void {
    [$variant, $warehouse] = quarantinedInventory();
    $actor = quarantineActor();
    $service = app(InventoryConditionChangeService::class);
    $change = draftDisposition($service, $actor, $variant, $warehouse, QuarantineDisposition::ReleaseToSaleable);

    $cancelled = $service->cancel($change, $actor, 'Inspection must be repeated.');

    expect($cancelled->status)->toBe(InventoryConditionChangeStatus::Cancelled)
        ->and(InventoryMovement::query()
            ->where('source_type', 'inventory_condition_change')
            ->where('source_id', $change->getKey())
            ->count())->toBe(0);
});

it('returns quarantined stock through the canonical supplier return workflow', function (): void {
    [$variant, $warehouse, $stock] = quarantinedInventory('2.000000');
    $actor = quarantineActor();
    $supplier = Supplier::factory()->create();
    $receipt = InventoryOperation::factory()->receipt()->done()->create([
        'destination_warehouse_id' => $warehouse->getKey(),
        'supplier_id' => $supplier->getKey(),
    ]);
    $line = InventoryOperationLine::factory()
        ->for($receipt, 'operation')
        ->for($variant, 'productVariant')
        ->create([
            'quantity' => '2.000000',
            'unit_id' => $variant->unit_id,
            'transaction_quantity' => '2.000000',
            'transaction_unit_id' => $variant->unit_id,
            'conversion_factor_snapshot' => '1.000000',
            'base_quantity' => '2.000000',
        ]);

    InventoryMovement::query()->forceCreate([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'movement_type' => MovementType::Receipt,
        'quantity' => '2.000',
        'source_type' => 'inventory_operation',
        'source_id' => $receipt->getKey(),
        'source_line_type' => 'inventory_operation_line',
        'source_line_id' => $line->getKey(),
        'transaction_quantity' => '2.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity_delta' => '2.000000',
        'stock_condition_from' => StockCondition::Quarantine,
        'stock_condition_to' => StockCondition::Quarantine,
        'status' => 'confirmed',
    ]);

    $service = app(InventoryConditionChangeService::class);
    $change = draftDisposition($service, $actor, $variant, $warehouse, QuarantineDisposition::ReturnToSupplier, '2.000000');

    $posted = $service->post($change, $actor);

    expect($posted->supplier_return_id)->not->toBeNull()
        ->and($posted->inventory_movement_id)->toBeNull()
        ->and($posted->supplierReturn?->isPosted())->toBeTrue()
        ->and($stock->fresh()?->on_hand_quantity)->toBe('0.000000')
        ->and(InventoryMovement::query()
            ->where('source_type', 'inventory_condition_change')
            ->where('source_id', $change->getKey())
            ->count())->toBe(0)
        ->and(InventoryMovement::query()
            ->where('source_type', 'inventory_return')
            ->where('source_id', $posted->supplier_return_id)
            ->count())->toBe(1);
});

it('refuses actors without the condition-change permission', function (): void {
    [$variant, $warehouse] = quarantinedInventory();
    $actor = User::factory()->create();
    $service = app(InventoryConditionChangeService::class);

    expect(fn (): \App\Models\InventoryConditionChange => draftDisposition(
        $service,
        $actor,
        $variant,
        $warehouse,
        QuarantineDisposition::ReleaseToSaleable,
    ))->toThrow(AuthorizationException::class);
});
