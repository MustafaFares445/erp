<?php

declare(strict_types=1);

use App\Data\Inventory\DamageDraftData;
use App\Data\Inventory\DisposalDraftData;
use App\Data\Inventory\RecoveryDraftData;
use App\Enums\ConditionChangeReason;
use App\Enums\InventoryConditionChangeStatus;
use App\Enums\InventoryConditionChangeType;
use App\Enums\InventoryPermission;
use App\Enums\MovementType;
use App\Enums\StockCondition;
use App\Models\AuditLog;
use App\Models\InventoryConditionChange;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Inventory\InventoryConditionChangeService;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

function conditionChangeActor(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo([
        InventoryPermission::ConditionChangeView->value,
        InventoryPermission::ConditionChangeCreate->value,
        InventoryPermission::ConditionChangePost->value,
        InventoryPermission::ConditionChangeCancel->value,
        InventoryPermission::StockView->value,
    ]);

    return $actor;
}

/** @return array{0: InventoryStock, 1: InventoryLot} */
function damageableStock(): array
{
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

    return [$stock, $lot];
}

it('posts a damage document through the same movement/balance shape the legacy modal path used', function (): void {
    $actor = conditionChangeActor();
    [$stock, $lot] = damageableStock();
    $service = app(InventoryConditionChangeService::class);

    $damage = $service->draftDamage(new DamageDraftData(
        productVariantId: (int) $stock->product_variant_id,
        warehouseId: (int) $stock->warehouse_id,
        inventoryLotId: $lot->getKey(),
        serializedInventoryUnitId: null,
        baseQuantity: '3.000000',
        reasonCategory: ConditionChangeReason::DamagedInTransit,
        reason: 'Transit damage',
    ), $actor);

    expect($damage->type)->toBe(InventoryConditionChangeType::Damage)
        ->and($damage->status)->toBe(InventoryConditionChangeStatus::Draft)
        ->and($damage->condition_from)->toBe(StockCondition::Saleable)
        ->and($damage->condition_to)->toBe(StockCondition::Damaged);

    $posted = $service->post($damage, $actor);

    expect($posted->status)->toBe(InventoryConditionChangeStatus::Posted)
        ->and($posted->inventory_movement_id)->not->toBeNull();

    $stock = $stock->fresh();
    expect((float) $stock->on_hand_quantity)->toEqual(10.0)
        ->and((float) $stock->reserved_quantity)->toEqual(2.0)
        ->and((float) $stock->damaged_quantity)->toEqual(3.0)
        ->and((float) $stock->available_quantity)->toEqual(5.0);

    $movement = InventoryMovement::query()
        ->where('source_type', 'stock_damage')
        ->where('source_id', $stock->getKey())
        ->sole();

    expect($movement->movement_type)->toBe(MovementType::Damage)
        ->and((float) $movement->quantity)->toEqual(-3.0)
        ->and((float) $movement->transaction_quantity)->toEqual(3.0)
        ->and($movement->getKey())->toBe($posted->inventory_movement_id)
        ->and(AuditLog::query()->where('subject_type', InventoryStock::class)->count())->toBe(1);
});

it('recovers and disposes through documents with the same movements as the legacy path, capping recovery in aggregate', function (): void {
    $actor = conditionChangeActor();
    [$stock, $lot] = damageableStock();
    $service = app(InventoryConditionChangeService::class);

    $damage = $service->draftDamage(new DamageDraftData(
        productVariantId: (int) $stock->product_variant_id,
        warehouseId: (int) $stock->warehouse_id,
        inventoryLotId: $lot->getKey(),
        serializedInventoryUnitId: null,
        baseQuantity: '3.000000',
        reasonCategory: ConditionChangeReason::DamagedInTransit,
        reason: 'Transit damage',
    ), $actor);
    $service->post($damage, $actor);

    // First partial recovery.
    $recovery1 = $service->draftRecovery(new RecoveryDraftData(
        reversesConditionChangeId: (int) $damage->getKey(),
        baseQuantity: '1.000000',
        reasonCategory: ConditionChangeReason::QualityInspectionPassed,
        reason: 'Repaired',
    ), $actor);

    expect($recovery1->condition_from)->toBe(StockCondition::Damaged)
        ->and($recovery1->condition_to)->toBe(StockCondition::Saleable)
        ->and($recovery1->product_variant_id)->toBe($damage->product_variant_id)
        ->and($recovery1->warehouse_id)->toBe($damage->warehouse_id)
        ->and($recovery1->inventory_lot_id)->toBe($damage->inventory_lot_id);

    $service->post($recovery1, $actor);

    $stock = $stock->fresh();
    expect((float) $stock->damaged_quantity)->toEqual(2.0)
        ->and((float) $stock->available_quantity)->toEqual(6.0);

    // Second partial recovery, capped at the remaining 2.
    $recovery2 = $service->draftRecovery(new RecoveryDraftData(
        reversesConditionChangeId: (int) $damage->getKey(),
        baseQuantity: '2.000000',
        reasonCategory: ConditionChangeReason::QualityInspectionPassed,
        reason: 'Repaired',
    ), $actor);
    $service->post($recovery2, $actor);

    $stock = $stock->fresh();
    expect((float) $stock->damaged_quantity)->toEqual(0.0)
        ->and((float) $stock->available_quantity)->toEqual(8.0);

    $movements = InventoryMovement::query()
        ->where('source_type', 'stock_damage')
        ->where('source_id', $stock->getKey())
        ->orderBy('id')
        ->pluck('movement_type');

    expect($movements->all())->toBe([
        MovementType::Damage,
        MovementType::DamageRecovery,
        MovementType::DamageRecovery,
    ]);
});

it('throws when a recovery does not reference a posted damage document', function (): void {
    $actor = conditionChangeActor();
    $service = app(InventoryConditionChangeService::class);

    expect(fn (): InventoryConditionChange => $service->draftRecovery(new RecoveryDraftData(
        reversesConditionChangeId: 999_999,
        baseQuantity: '1.000000',
        reasonCategory: ConditionChangeReason::QualityInspectionPassed,
        reason: 'Repaired',
    ), $actor))->toThrow(DomainException::class, __('admin.inventory.damage.errors.invalid_reversal'));
});

it('throws when a recovery exceeds the outstanding damaged quantity', function (): void {
    $actor = conditionChangeActor();
    [$stock, $lot] = damageableStock();
    $service = app(InventoryConditionChangeService::class);

    $damage = $service->draftDamage(new DamageDraftData(
        productVariantId: (int) $stock->product_variant_id,
        warehouseId: (int) $stock->warehouse_id,
        inventoryLotId: $lot->getKey(),
        serializedInventoryUnitId: null,
        baseQuantity: '3.000000',
        reasonCategory: ConditionChangeReason::DamagedInTransit,
        reason: 'Transit damage',
    ), $actor);
    $service->post($damage, $actor);

    expect(fn (): InventoryConditionChange => $service->draftRecovery(new RecoveryDraftData(
        reversesConditionChangeId: (int) $damage->getKey(),
        baseQuantity: '3.000001',
        reasonCategory: ConditionChangeReason::QualityInspectionPassed,
        reason: 'Repaired',
    ), $actor))->toThrow(DomainException::class, __('admin.inventory.damage.errors.recovery_exceeds_damage'));
});

it('throws when disposal is posted without attached evidence', function (): void {
    $actor = conditionChangeActor();
    $authoriser = conditionChangeActor();
    [$stock, $lot] = damageableStock();
    $service = app(InventoryConditionChangeService::class);

    $damage = $service->draftDamage(new DamageDraftData(
        productVariantId: (int) $stock->product_variant_id,
        warehouseId: (int) $stock->warehouse_id,
        inventoryLotId: $lot->getKey(),
        serializedInventoryUnitId: null,
        baseQuantity: '3.000000',
        reasonCategory: ConditionChangeReason::DamagedInTransit,
        reason: 'Transit damage',
    ), $actor);
    $service->post($damage, $actor);

    $disposal = $service->draftDisposal(new DisposalDraftData(
        productVariantId: (int) $stock->product_variant_id,
        warehouseId: (int) $stock->warehouse_id,
        inventoryLotId: $lot->getKey(),
        serializedInventoryUnitId: null,
        baseQuantity: '3.000000',
        reasonCategory: ConditionChangeReason::Other,
        reason: 'Beyond repair',
        authorisedBy: (int) $authoriser->getKey(),
    ), $actor);

    expect(fn (): InventoryConditionChange => $service->post($disposal, $actor))
        ->toThrow(DomainException::class, __('admin.inventory.damage.errors.disposal_evidence_required'));
});

it('throws when disposal is authorised by its own creator', function (): void {
    $actor = conditionChangeActor();
    [$stock, $lot] = damageableStock();

    expect(fn (): InventoryConditionChange => app(InventoryConditionChangeService::class)->draftDisposal(new DisposalDraftData(
        productVariantId: (int) $stock->product_variant_id,
        warehouseId: (int) $stock->warehouse_id,
        inventoryLotId: $lot->getKey(),
        serializedInventoryUnitId: null,
        baseQuantity: '1.000000',
        reasonCategory: ConditionChangeReason::Other,
        reason: 'Beyond repair',
        authorisedBy: (int) $actor->getKey(),
    ), $actor))->toThrow(DomainException::class, __('admin.inventory.damage.errors.disposal_authoriser_required'));
});

it('posts disposal with evidence attached by an authoriser distinct from the creator', function (): void {
    $actor = conditionChangeActor();
    $authoriser = conditionChangeActor();
    [$stock, $lot] = damageableStock();
    $service = app(InventoryConditionChangeService::class);

    $damage = $service->draftDamage(new DamageDraftData(
        productVariantId: (int) $stock->product_variant_id,
        warehouseId: (int) $stock->warehouse_id,
        inventoryLotId: $lot->getKey(),
        serializedInventoryUnitId: null,
        baseQuantity: '3.000000',
        reasonCategory: ConditionChangeReason::DamagedInTransit,
        reason: 'Transit damage',
    ), $actor);
    $service->post($damage, $actor);

    $disposal = $service->draftDisposal(new DisposalDraftData(
        productVariantId: (int) $stock->product_variant_id,
        warehouseId: (int) $stock->warehouse_id,
        inventoryLotId: $lot->getKey(),
        serializedInventoryUnitId: null,
        baseQuantity: '3.000000',
        reasonCategory: ConditionChangeReason::Other,
        reason: 'Beyond repair',
        authorisedBy: (int) $authoriser->getKey(),
    ), $actor);

    $disposal->addMediaFromString('scrap photo')
        ->usingFileName('scrap.jpg')
        ->toMediaCollection('disposal-evidence');

    $posted = $service->post($disposal->fresh(), $actor);

    expect($posted->status)->toBe(InventoryConditionChangeStatus::Posted)
        ->and($stock->fresh()->on_hand_quantity)->toBe('7.000000')
        ->and($stock->fresh()->damaged_quantity)->toBe('0.000000');
});

it('renders legacy damage movements without a condition-change document as read-only history', function (): void {
    $variant = ProductVariant::factory()->create();
    $stock = InventoryStock::factory()->for($variant, 'productVariant')->create();

    $legacyMovement = InventoryMovement::query()->forceCreate([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $stock->warehouse_id,
        'movement_type' => MovementType::Damage,
        'quantity' => '-1.000',
        'source_type' => 'stock_damage',
        'source_id' => $stock->getKey(),
        'transaction_quantity' => '1.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity_delta' => '-1.000000',
        'stock_condition_from' => StockCondition::Saleable,
        'stock_condition_to' => StockCondition::Damaged,
        'status' => 'confirmed',
    ]);

    $linkedDocumentIds = InventoryConditionChange::query()
        ->whereNotNull('inventory_movement_id')
        ->pluck('inventory_movement_id');

    expect($linkedDocumentIds->contains($legacyMovement->getKey()))->toBeFalse();

    $isLegacy = InventoryMovement::query()
        ->whereIn('movement_type', [
            MovementType::Damage->value,
            MovementType::DamageRecovery->value,
            MovementType::Disposal->value,
        ])
        ->whereNotIn('id', InventoryConditionChange::query()->whereNotNull('inventory_movement_id')->pluck('inventory_movement_id'))
        ->where('id', $legacyMovement->getKey())
        ->exists();

    expect($isLegacy)->toBeTrue();
});
