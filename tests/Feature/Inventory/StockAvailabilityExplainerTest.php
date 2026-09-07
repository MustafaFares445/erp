<?php

declare(strict_types=1);

use App\Enums\ConditionChangeReason;
use App\Enums\InventoryConditionChangeStatus;
use App\Enums\InventoryConditionChangeType;
use App\Enums\MovementType;
use App\Enums\QuarantineDisposition;
use App\Enums\ReservationStatus;
use App\Enums\StockCondition;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryConditionChange;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockAvailabilityExplainer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Mirrors the canonical (saleable/quarantine/damaged) condition-balance rows
 * a real posting would leave behind, the same way
 * tests/Feature/Filament/StockLevelResourceTest.php seeds them, so the
 * explainer's on-hand/available/gap invariant holds exactly.
 */
function seedExplainerConditionBalances(
    ProductVariant $variant,
    Warehouse $warehouse,
    string $saleableOnHand,
    string $saleableReserved,
    string $quarantineOnHand,
    string $damagedOnHand,
): void {
    foreach ([
        [StockCondition::Saleable, $saleableOnHand, $saleableReserved],
        [StockCondition::Quarantine, $quarantineOnHand, '0.000000'],
        [StockCondition::Damaged, $damagedOnHand, '0.000000'],
    ] as [$condition, $onHand, $reserved]) {
        InventoryConditionBalance::query()->forceCreate([
            'product_variant_id' => $variant->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'stock_condition' => $condition->value,
            'on_hand_base_quantity' => $onHand,
            'reserved_base_quantity' => $reserved,
        ]);
    }
}

it('attributes the entire on-hand/available gap to reserved, quarantine, and damaged causes with no residual', function (): void {
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $actor = User::factory()->create();

    InventoryStock::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '15.000000',
        'reserved_quantity' => '4.000000',
        'damaged_quantity' => '2.000000',
        'available_quantity' => '6.000000',
    ]);

    seedExplainerConditionBalances($variant, $warehouse, '10.000000', '4.000000', '3.000000', '2.000000');

    // Reserved: an active reservation held by a delivery operation.
    $deliveryOperation = InventoryOperation::factory()->delivery()->done()->create([
        'source_warehouse_id' => $warehouse->getKey(),
    ]);
    $reservation = InventoryReservation::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'source_type' => 'inventory_operation',
        'source_id' => $deliveryOperation->getKey(),
        'base_quantity' => '4.000000',
        'status' => ReservationStatus::Active,
        'expires_at' => null,
    ]);

    // Quarantine: an inbound receipt movement plus an open (draft) disposition.
    $receiptOperation = InventoryOperation::factory()->receipt()->done()->create([
        'destination_warehouse_id' => $warehouse->getKey(),
    ]);
    InventoryMovement::query()->forceCreate([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'movement_type' => MovementType::Receipt->value,
        'quantity' => '3.000',
        'source_type' => 'inventory_operation',
        'source_id' => $receiptOperation->getKey(),
        'transaction_quantity' => '3.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity_delta' => '3.000000',
        'stock_condition_to' => StockCondition::Quarantine->value,
        'status' => 'confirmed',
    ]);
    $disposition = InventoryConditionChange::query()->forceCreate([
        'document_number' => 'ICC-TEST-0001',
        'type' => InventoryConditionChangeType::QuarantineDisposition,
        'status' => InventoryConditionChangeStatus::Draft,
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'condition_from' => StockCondition::Quarantine,
        'condition_to' => QuarantineDisposition::ReleaseToSaleable->conditionTo(),
        'base_quantity' => '1.000000',
        'disposition' => QuarantineDisposition::ReleaseToSaleable,
        'reason_category' => ConditionChangeReason::QualityInspectionPassed,
        'reason' => 'Passed inspection',
        'created_by' => $actor->getKey(),
    ]);

    // Damaged: a posted damage document.
    $damage = InventoryConditionChange::query()->forceCreate([
        'document_number' => 'ICC-TEST-0002',
        'type' => InventoryConditionChangeType::Damage,
        'status' => InventoryConditionChangeStatus::Posted,
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'condition_from' => StockCondition::Saleable,
        'condition_to' => StockCondition::Damaged,
        'base_quantity' => '2.000000',
        'reason_category' => ConditionChangeReason::DamagedInTransit,
        'reason' => 'Dropped',
        'created_by' => $actor->getKey(),
    ]);

    $explanation = app(StockAvailabilityExplainer::class)->explain($variant, $warehouse);

    expect($explanation['on_hand'])->toBe(15.0)
        ->and($explanation['available'])->toBe(6.0)
        ->and($explanation['gap'])->toBe(9.0)
        ->and($explanation['causes'])->toHaveCount(3);

    expect(array_sum(array_column($explanation['causes'], 'quantity')))->toBe($explanation['gap']);

    $reservedCause = collect($explanation['causes'])->firstWhere('cause', 'reserved');
    expect($reservedCause['quantity'])->toBe(4.0)
        ->and($reservedCause['documents'])->toHaveCount(1)
        ->and($reservedCause['documents'][0]['url'])->toContain((string) $reservation->getKey())
        ->and($reservedCause['documents'][0]['holder'])->toBe((string) $deliveryOperation->operation_number)
        ->and($reservedCause['action'])->not->toBeNull();

    $quarantineCause = collect($explanation['causes'])->firstWhere('cause', 'quarantine');
    expect($quarantineCause['quantity'])->toBe(3.0)
        ->and($quarantineCause['documents'])->toHaveCount(2);

    $inboundDocument = collect($quarantineCause['documents'])->firstWhere('type', 'inventory_operation');
    expect($inboundDocument['quantity'])->toBe(3.0)
        ->and($inboundDocument['url'])->toContain((string) $receiptOperation->getKey());

    $dispositionDocument = collect($quarantineCause['documents'])->firstWhere('type', 'inventory_condition_change');
    expect($dispositionDocument['label'])->toBe($disposition->document_number)
        ->and($dispositionDocument['quantity'])->toBe(1.0)
        // An open disposition is already handling the quarantined quantity, so no further
        // "create a disposition" action is offered.
        ->and($quarantineCause['action'])->toBeNull();

    $damagedCause = collect($explanation['causes'])->firstWhere('cause', 'damaged');
    expect($damagedCause['quantity'])->toBe(2.0)
        ->and($damagedCause['documents'])->toHaveCount(1)
        ->and($damagedCause['documents'][0]['label'])->toBe($damage->document_number)
        ->and($damagedCause['documents'][0]['url'])->toContain((string) $damage->getKey())
        ->and($damagedCause['action'])->not->toBeNull();
});

it('returns an empty cause list, not a zero-filled one, when there is no unavailability', function (): void {
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();

    InventoryStock::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '10.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '10.000000',
    ]);

    seedExplainerConditionBalances($variant, $warehouse, '10.000000', '0.000000', '0.000000', '0.000000');

    $explanation = app(StockAvailabilityExplainer::class)->explain($variant, $warehouse);

    expect($explanation['gap'])->toBe(0.0)
        ->and($explanation['causes'])->toBe([])
        ->and($explanation['in_transit']['quantity'])->toBe(0.0)
        ->and($explanation['expired']['quantity'])->toBe(0.0);
});

it('surfaces in-transit transfers and expired lots as read-only context without inflating causes or the gap', function (): void {
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();

    InventoryStock::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '10.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '10.000000',
    ]);
    seedExplainerConditionBalances($variant, $warehouse, '10.000000', '0.000000', '0.000000', '0.000000');

    $transfer = InventoryOperation::factory()->internalTransfer()->inTransit()->create([
        'destination_warehouse_id' => $warehouse->getKey(),
    ]);
    InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $transfer->getKey(),
        'product_variant_id' => $variant->getKey(),
        'dispatched_base_quantity' => '5.000000',
        'received_base_quantity' => '2.000000',
    ]);

    $expiredLot = InventoryLot::factory()->for($variant)->for($warehouse)->create([
        'expires_at' => today()->subDay(),
        'on_hand_quantity' => '4.000000',
        'reserved_quantity' => '0.000000',
    ]);

    $explanation = app(StockAvailabilityExplainer::class)->explain($variant, $warehouse);

    expect($explanation['gap'])->toBe(0.0)
        ->and($explanation['causes'])->toBe([])
        ->and($explanation['in_transit']['quantity'])->toBe(3.0)
        ->and($explanation['in_transit']['operations'])->toHaveCount(1)
        ->and($explanation['in_transit']['operations'][0]['url'])->toContain((string) $transfer->getKey())
        ->and($explanation['expired']['quantity'])->toBe(4.0)
        ->and($explanation['expired']['lots'])->toHaveCount(1)
        ->and($explanation['expired']['lots'][0]['label'])->toBe($expiredLot->lot_number)
        ->and($explanation['expired']['lots'][0]['url'])->toContain((string) $expiredLot->getKey());
});

it('returns zeroed metrics and no causes for a variant/warehouse with no stock row at all', function (): void {
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();

    $explanation = app(StockAvailabilityExplainer::class)->explain($variant, $warehouse);

    expect($explanation['on_hand'])->toBe(0.0)
        ->and($explanation['available'])->toBe(0.0)
        ->and($explanation['gap'])->toBe(0.0)
        ->and($explanation['causes'])->toBe([]);
});
