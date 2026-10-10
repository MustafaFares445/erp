<?php

declare(strict_types=1);

use App\Data\Inventory\DamageDraftData;
use App\Data\Inventory\DisposalDraftData;
use App\Data\Inventory\QuarantineDispositionData;
use App\Data\Inventory\RecoveryDraftData;
use App\Enums\ConditionChangeReason;
use App\Enums\InventoryConditionChangeStatus;
use App\Enums\InventoryPermission;
use App\Enums\InventoryReturnStatus;
use App\Enums\MovementType;
use App\Enums\QuarantineDisposition;
use App\Enums\StockCondition;
use App\Models\CreditNote;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryConditionChangeService;
use App\Services\Inventory\InventoryReturnService;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

function phase4ConditionActor(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo([
        InventoryPermission::ConditionChangeView->value,
        InventoryPermission::ConditionChangeCreate->value,
        InventoryPermission::ConditionChangePost->value,
        InventoryPermission::ConditionChangeCancel->value,
        InventoryPermission::StockView->value,
    ]);

    return $user;
}

it('sends supplier-return quantities out of warehouse custody without creating customer credit notes or refunds', function (): void {
    $warehouse = Warehouse::factory()->create();
    $supplier = Supplier::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $actor = User::factory()->create();
    $stock = InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '5.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '5.000000',
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '5.000000', 'reserved_quantity' => '0.000000', 'expires_at' => null,
    ]);

    $service = app(InventoryReturnService::class);
    $return = $service->createSupplierReturn($actor, $supplier, $warehouse);
    $line = $service->addSupplierLine($return, $variant, (int) $variant->unit_id, '2.000000', StockCondition::Saleable, (int) $lot->getKey());

    expect(InventoryMovement::query()->count())->toBe(0)
        ->and($stock->refresh()->on_hand_quantity)->toBe('5.000000');

    $service->markReady($return, $actor);
    $posted = $service->post($return->refresh(), $actor);
    $movement = InventoryMovement::query()
        ->where('source_type', 'inventory_return')
        ->where('source_id', $return->getKey())->sole();

    expect($posted->status)->toBe(InventoryReturnStatus::Posted)
        ->and($stock->refresh()->on_hand_quantity)->toBe('3.000000')
        ->and($line->refresh()->posted_base_quantity)->toBe('2.000000')
        ->and($movement->quantity)->toBe('-2.000000')
        ->and($movement->movement_type)->toBe(MovementType::Return)
        ->and(CreditNote::query()->count())->toBe(0)
        ->and(Refund::query()->count())->toBe(0);
});

it('releases quarantine to saleable without increasing aggregate on-hand twice', function (): void {
    $variant = ProductVariant::factory()->grain()->create();
    $warehouse = Warehouse::factory()->create();
    $stock = InventoryStock::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '10.000000', 'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000', 'available_quantity' => '0.000000',
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '10.000000', 'reserved_quantity' => '0.000000',
    ]);
    foreach ([StockCondition::Saleable->value => '0.000000', StockCondition::Quarantine->value => '10.000000', StockCondition::Damaged->value => '0.000000'] as $condition => $quantity) {
        InventoryConditionBalance::query()->firstOrNew([
            'product_variant_id' => $variant->getKey(), 'warehouse_id' => $warehouse->getKey(), 'stock_condition' => $condition,
        ])->forceFill(['on_hand_base_quantity' => $quantity, 'reserved_base_quantity' => '0.000000'])->save();
        InventoryLotBalance::query()->firstOrNew([
            'inventory_lot_id' => $lot->getKey(), 'warehouse_id' => $warehouse->getKey(), 'stock_condition' => $condition,
        ])->forceFill(['on_hand_base_quantity' => $quantity, 'reserved_base_quantity' => '0.000000'])->save();
    }

    $actor = phase4ConditionActor();
    $service = app(InventoryConditionChangeService::class);
    $document = $service->draftQuarantineDisposition(new QuarantineDispositionData(
        productVariantId: (int) $variant->getKey(), warehouseId: (int) $warehouse->getKey(),
        inventoryLotId: (int) $lot->getKey(), serializedInventoryUnitId: null,
        baseQuantity: '3.000000', disposition: QuarantineDisposition::ReleaseToSaleable,
        reasonCategory: ConditionChangeReason::QualityInspectionPassed, reason: 'QA released three inspected units',
    ), $actor);
    expect($document->status)->toBe(InventoryConditionChangeStatus::Draft)
        ->and($stock->refresh()->available_quantity)->toBe('0.000000');
    $posted = $service->post($document, $actor);

    expect($posted->status)->toBe(InventoryConditionChangeStatus::Posted)
        ->and($stock->refresh()->on_hand_quantity)->toBe('10.000000')
        ->and($stock->available_quantity)->toBe('3.000000')
        ->and(InventoryConditionBalance::query()->where('product_variant_id', $variant->getKey())
            ->where('stock_condition', StockCondition::Quarantine->value)->value('on_hand_base_quantity'))->toBe('7.000000')
        ->and(InventoryConditionBalance::query()->where('product_variant_id', $variant->getKey())
            ->where('stock_condition', StockCondition::Saleable->value)->value('on_hand_base_quantity'))->toBe('3.000000');
});

it('caps damage recovery and requires authorization and evidence before irreversible disposal', function (): void {
    $actor = phase4ConditionActor();
    $authoriser = phase4ConditionActor();
    $stock = InventoryStock::factory()->create([
        'on_hand_quantity' => '10.000000', 'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000', 'available_quantity' => '10.000000',
    ]);
    $lot = InventoryLot::factory()->for($stock->productVariant)->for($stock->warehouse)->create([
        'on_hand_quantity' => '10.000000', 'reserved_quantity' => '0.000000',
    ]);
    $service = app(InventoryConditionChangeService::class);
    $damage = $service->draftDamage(new DamageDraftData(
        productVariantId: (int) $stock->product_variant_id, warehouseId: (int) $stock->warehouse_id,
        inventoryLotId: (int) $lot->getKey(), serializedInventoryUnitId: null,
        baseQuantity: '3.000000', reasonCategory: ConditionChangeReason::DamagedInTransit,
        reason: 'Three units damaged',
    ), $actor);
    $service->post($damage, $actor);

    $recovery = $service->draftRecovery(new RecoveryDraftData(
        reversesConditionChangeId: (int) $damage->getKey(), baseQuantity: '1.000000',
        reasonCategory: ConditionChangeReason::QualityInspectionPassed, reason: 'One unit fixed',
    ), $actor);
    $service->post($recovery, $actor);
    expect($stock->refresh()->damaged_quantity)->toBe('2.000000')
        ->and($stock->available_quantity)->toBe('8.000000');

    expect(fn () => $service->draftRecovery(new RecoveryDraftData(
        reversesConditionChangeId: (int) $damage->getKey(), baseQuantity: '3.000000',
        reasonCategory: ConditionChangeReason::QualityInspectionPassed, reason: 'Invalid excess',
    ), $actor))->toThrow(DomainException::class);

    $disposal = $service->draftDisposal(new DisposalDraftData(
        productVariantId: (int) $stock->product_variant_id, warehouseId: (int) $stock->warehouse_id,
        inventoryLotId: (int) $lot->getKey(), serializedInventoryUnitId: null,
        baseQuantity: '2.000000', reasonCategory: ConditionChangeReason::Other,
        reason: 'Unrecoverable damage', authorisedBy: (int) $authoriser->getKey(),
    ), $actor);

    expect(fn () => $service->post($disposal, $actor))->toThrow(DomainException::class);
    expect($stock->refresh()->on_hand_quantity)->toBe('10.000000');

    $disposal->addMediaFromString('disposal visual evidence')
        ->usingFileName('disposal.jpg')->toMediaCollection('disposal-evidence');
    $posted = $service->post($disposal->refresh(), $actor);

    expect($posted->status)->toBe(InventoryConditionChangeStatus::Posted)
        ->and($stock->refresh()->on_hand_quantity)->toBe('8.000000')
        ->and($stock->damaged_quantity)->toBe('0.000000')
        ->and(InventoryMovement::query()->where('movement_type', MovementType::Disposal->value)->count())->toBe(1);
});
