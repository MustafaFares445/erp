<?php

declare(strict_types=1);

use App\Enums\ExternalRepairStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\NotificationEventKey;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Enums\WarrantyDurationUnit;
use App\Enums\WarrantyEntitlementState;
use App\Enums\WarrantyRecoveryOutcome;
use App\Events\SupportContinuityMilestone;
use App\Models\CustomerProfile;
use App\Models\MaintenanceExternalRepair;
use App\Models\MaintenanceRecord;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WarrantyEntitlement;
use App\Models\WarrantyRecoveryClaim;
use App\Services\Support\Exceptions\InvalidStatusTransition;
use App\Services\Support\ExternalRepairService;
use App\Services\Support\MaintenanceRecordService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\ContinuityFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ContinuityFixtures::seedPermissions();
    config(['support.external_repair_enabled' => true]);
});

/** @return array{0: MaintenanceRecord, 1: SerializedInventoryUnit, 2: Supplier} */
function rmaScenario(): array
{
    [, $unit, $record] = ContinuityFixtures::repairScenario();

    return [$record, $unit, Supplier::factory()->create()];
}

function repairAt(ExternalRepairStatus $status): MaintenanceExternalRepair
{
    [$record, $unit, $supplier] = rmaScenario();
    $operator = ContinuityFixtures::operator();
    $service = app(ExternalRepairService::class);
    $repair = $service->request($record, $supplier, $operator, ['rma_number' => 'RMA-1', 'reason' => 'Bearing failure']);
    $order = [
        ExternalRepairStatus::Approved,
        ExternalRepairStatus::ShippedToSupplier,
        ExternalRepairStatus::ReceivedBySupplier,
        ExternalRepairStatus::Repairing,
    ];

    foreach ($order as $step) {
        if ($repair->status === $status) {
            break;
        }

        $repair = match ($step) {
            ExternalRepairStatus::Approved => $service->approve($repair, $operator),
            ExternalRepairStatus::ShippedToSupplier => (function () use ($service, $repair, $unit, $operator): MaintenanceExternalRepair {
                ContinuityFixtures::receiveIntoWarehouse($unit);

                return $service->ship($repair, $operator, 'AWB-1');
            })(),
            ExternalRepairStatus::ReceivedBySupplier => $service->markReceivedBySupplier($repair, $operator, 'SUP-9'),
            default => $service->startRepair($repair, $operator, 'Worn bearings', now()->addDays(10)),
        };
    }

    return $repair;
}

it("requests a supplier repair for the customer's equipment and audits it", function (): void {
    [$record, $unit, $supplier] = rmaScenario();
    $operator = ContinuityFixtures::operator();

    $repair = app(ExternalRepairService::class)->request($record, $supplier, $operator, [
        'rma_number' => 'RMA-77',
        'reason' => 'Bearing failure',
        'estimated_return_on' => now()->addDays(14),
    ]);

    expect($repair->status)->toBe(ExternalRepairStatus::Requested)
        ->and($repair->serialized_inventory_unit_id)->toBe($unit->id)
        ->and($repair->supplier->is($supplier))->toBeTrue()
        ->and($repair->maintenanceRecord->is($record))->toBeTrue()
        ->and($repair->serializedInventoryUnit->is($unit))->toBeTrue()
        ->and($repair->rma_number)->toBe('RMA-77')
        ->and($repair->isOverdue())->toBeFalse()
        ->and($record->externalRepairs()->count())->toBe(1)
        // Requesting never touches custody.
        ->and($unit->fresh()->custody_type)->toBe(SerializedCustodyType::Customer)
        ->and(Activity::query()->where('description', 'support.rma.requested')->count())->toBe(1);
});

it('rejects requests for closed requests, unlinked or misplaced equipment, inactive suppliers and open duplicates', function (): void {
    $operator = ContinuityFixtures::operator();
    $service = app(ExternalRepairService::class);

    [$closed, , $supplier] = rmaScenario();
    $closed->update(['status' => MaintenanceStatus::Closed]);
    expect(fn () => $service->request($closed, $supplier, $operator))->toThrow(ValidationException::class, 'closed or cancelled');

    [$unlinked, , $supplier] = rmaScenario();
    $unlinked->update(['serialized_inventory_unit_id' => null]);
    expect(fn () => $service->request($unlinked, $supplier, $operator))->toThrow(ValidationException::class, 'serialized equipment');

    [$inactive] = rmaScenario();
    expect(fn () => $service->request($inactive, Supplier::factory()->create(['is_active' => false]), $operator))->toThrow(ValidationException::class, 'active supplier');

    [$elsewhere, $unit, $supplier] = rmaScenario();
    $unit->forceFill(['custody_type' => SerializedCustodyType::Supplier])->save();
    expect(fn () => $service->request($elsewhere, $supplier, $operator))->toThrow(ValidationException::class, 'with the maintenance request customer or in a warehouse');

    [$dupe, $unit, $supplier] = rmaScenario();
    $service->request($dupe, $supplier, $operator);
    $second = MaintenanceRecord::factory()->create(['customer_id' => $dupe->customer_id, 'serialized_inventory_unit_id' => $unit->id]);
    expect(fn () => $service->request($second, $supplier, $operator))->toThrow(ValidationException::class, 'open supplier repair already exists');
});

it('allows requesting a repair for a unit already received into a warehouse', function (): void {
    [$record, $unit, $supplier] = rmaScenario();
    ContinuityFixtures::receiveIntoWarehouse($unit);

    expect(app(ExternalRepairService::class)->request($record, $supplier, ContinuityFixtures::operator())->status)->toBe(ExternalRepairStatus::Requested);
});

it('walks the repair path and moves custody through Inventory only when shipping and receiving back', function (): void {
    [$record, $unit, $supplier] = rmaScenario();
    $operator = ContinuityFixtures::operator();
    $service = app(ExternalRepairService::class);
    $repair = $service->request($record, $supplier, $operator);

    $repair = $service->approve($repair, $operator, 'RMA-5');
    expect($repair->status)->toBe(ExternalRepairStatus::Approved)->and($repair->rma_number)->toBe('RMA-5')->and($repair->approved_at)->not->toBeNull();

    // The unit is still with the customer, so Inventory refuses to ship it.
    expect(fn () => $service->ship($repair, $operator))->toThrow(DomainException::class, 'receive it into the warehouse first')
        ->and($repair->fresh()->status)->toBe(ExternalRepairStatus::Approved);

    ContinuityFixtures::receiveIntoWarehouse($unit);
    $repair = $service->ship($repair, $operator, 'AWB-9');
    $unit->refresh();

    expect($repair->status)->toBe(ExternalRepairStatus::ShippedToSupplier)
        ->and($repair->outbound_reference)->toBe('AWB-9')
        ->and($repair->ship_inventory_movement_id)->not->toBeNull()
        ->and($unit->custody_type)->toBe(SerializedCustodyType::Supplier)
        ->and($unit->status)->toBe(SerializedInventoryUnitStatus::ReturnedToSupplier)
        ->and((int) $unit->custody_reference_id)->toBe($supplier->id);

    $repair = $service->markReceivedBySupplier($repair, $operator, 'SUP-1');
    expect($repair->supplier_reference)->toBe('SUP-1')->and($repair->supplier_received_at)->not->toBeNull();

    $repair = $service->startRepair($repair, $operator, 'Worn bearings', now()->addDays(7));
    expect($repair->supplier_diagnosis)->toBe('Worn bearings')->and($repair->estimated_return_on->toDateString())->toBe(now()->addDays(7)->toDateString());

    expect(fn () => $service->recordRepaired($repair, $operator, ' '))->toThrow(ValidationException::class, 'resolution is required');

    $repair = $service->recordRepaired($repair, $operator, 'Bearings replaced');
    expect($repair->supplier_resolution)->toBe('Bearings replaced')->and($repair->completed_at)->not->toBeNull();

    $warehouse = ContinuityFixtures::warehouse();
    $repair = $service->returnToCompany($repair, $warehouse->id, StockCondition::Saleable, $operator, 'IN-4');
    $unit->refresh();

    expect($repair->status)->toBe(ExternalRepairStatus::ReturnedToCompany)
        ->and($repair->inbound_reference)->toBe('IN-4')
        ->and($repair->actual_return_on->toDateString())->toBe(now()->toDateString())
        ->and($repair->return_inventory_movement_id)->not->toBeNull()
        ->and($unit->custody_type)->toBe(SerializedCustodyType::Warehouse)
        ->and($unit->warehouse_id)->toBe($warehouse->id)
        ->and($repair->status->isClosed())->toBeTrue()
        ->and(Activity::query()->whereIn('description', ['support.rma.approved', 'support.rma.shipped', 'support.rma.received', 'support.rma.repairing', 'support.rma.repaired', 'support.rma.returned'])->count())->toBe(6);
});

it('refuses illegal status jumps and keeps the repair unchanged', function (): void {
    $repair = repairAt(ExternalRepairStatus::Requested);
    $operator = ContinuityFixtures::operator();
    $service = app(ExternalRepairService::class);

    expect(fn () => $service->markReceivedBySupplier($repair, $operator))->toThrow(ValidationException::class, 'cannot move from Requested to Received by supplier')
        ->and(fn () => $service->startRepair($repair, $operator))->toThrow(ValidationException::class, 'cannot move')
        ->and(fn () => $service->recordRepaired($repair, $operator, 'x'))->toThrow(ValidationException::class, 'cannot move')
        ->and(fn () => $service->returnToCompany($repair, 1, StockCondition::Saleable, $operator))->toThrow(ValidationException::class, 'cannot move')
        ->and($repair->fresh()->status)->toBe(ExternalRepairStatus::Requested)
        ->and(ExternalRepairStatus::Repairing->nextStatuses())->toBe([ExternalRepairStatus::Repaired, ExternalRepairStatus::ReplacementApproved])
        ->and(ExternalRepairStatus::Cancelled->nextStatuses())->toBe([])
        ->and(ExternalRepairStatus::openValues())->not->toContain('cancelled', 'returned_to_company', 'replacement_received');
});

it('cancels only before shipping and requires a reason', function (): void {
    $operator = ContinuityFixtures::operator();
    $service = app(ExternalRepairService::class);
    $repair = repairAt(ExternalRepairStatus::Requested);

    expect(fn () => $service->cancel($repair, $operator, ' '))->toThrow(ValidationException::class, 'reason is required');

    $cancelled = $service->cancel($repair, $operator, 'Customer declined');

    expect($cancelled->status)->toBe(ExternalRepairStatus::Cancelled)->and($cancelled->cancellation_reason)->toBe('Customer declined')->and($cancelled->reason)->toBe('Bearing failure');

    $shipped = repairAt(ExternalRepairStatus::ShippedToSupplier);

    expect(fn () => $service->cancel($shipped, $operator, 'Too late'))->toThrow(ValidationException::class, 'cannot move');
});

it('only lets users with both Support and Inventory permission ship or receive custody', function (): void {
    [$record, $unit, $supplier] = rmaScenario();
    $supportOnly = ContinuityFixtures::supportOnly();
    $service = app(ExternalRepairService::class);
    $repair = $service->approve($service->request($record, $supplier, $supportOnly), $supportOnly);
    ContinuityFixtures::receiveIntoWarehouse($unit);

    expect(fn () => $service->ship($repair, $supportOnly))->toThrow(AuthorizationException::class)
        ->and($unit->fresh()->custody_type)->toBe(SerializedCustodyType::Warehouse)
        ->and($repair->fresh()->status)->toBe(ExternalRepairStatus::Approved);
});

it('registers the supplier replacement and carries the active warranty to it', function (): void {
    [$record, $original, $supplier] = rmaScenario();
    $operator = ContinuityFixtures::operator();
    $service = app(ExternalRepairService::class);
    $entitlement = WarrantyEntitlement::factory()->create([
        'serialized_inventory_unit_id' => $original->id,
        'customer_id' => $record->customer_id,
        'state' => WarrantyEntitlementState::Active,
        'duration_value' => 12,
        'duration_unit' => WarrantyDurationUnit::Months,
        'starts_on' => now()->subMonths(2),
        'expires_on' => now()->addMonths(10),
        'replacement_rule' => 'remaining_original_term',
    ]);
    $repair = $service->approve($service->request($record, $supplier, $operator), $operator);
    ContinuityFixtures::receiveIntoWarehouse($original);
    $repair = $service->markReceivedBySupplier($service->ship($repair, $operator), $operator);
    $repair = $service->approveReplacement($service->startRepair($repair, $operator), $operator, 'Unrepairable; replaced');

    expect($repair->status)->toBe(ExternalRepairStatus::ReplacementApproved)->and($repair->supplier_resolution)->toBe('Unrepairable; replaced');

    $replacement = ContinuityFixtures::warehouseUnit(ProductVariant::query()->findOrFail($original->product_variant_id));
    $replacement->forceFill([
        'status' => SerializedInventoryUnitStatus::Delivered,
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $record->customer_id,
        'warehouse_id' => null,
    ])->save();

    $done = $service->recordReplacementReceived($repair, $replacement, $operator);

    expect($done->status)->toBe(ExternalRepairStatus::ReplacementReceived)
        ->and($done->replacement_serialized_inventory_unit_id)->toBe($replacement->id)
        ->and($done->replacementUnit->is($replacement))->toBeTrue()
        ->and(WarrantyEntitlement::query()->where('serialized_inventory_unit_id', $replacement->id)->where('state', WarrantyEntitlementState::Active->value)->exists())->toBeTrue()
        ->and($entitlement->fresh()->state)->not->toBe(WarrantyEntitlementState::Active)
        ->and(Activity::query()->where('description', 'support.rma.replaced')->count())->toBe(1);
});

it('validates the replacement unit: different, same product, same customer, used once', function (): void {
    $operator = ContinuityFixtures::operator();
    $service = app(ExternalRepairService::class);

    $newRepair = function (): array {
        [$record, $original, $supplier] = rmaScenario();
        $operator = ContinuityFixtures::operator();
        $service = app(ExternalRepairService::class);
        $repair = $service->request($record, $supplier, $operator);
        $repair = $service->approve($repair, $operator);
        ContinuityFixtures::receiveIntoWarehouse($original);
        $repair = $service->markReceivedBySupplier($service->ship($repair, $operator), $operator);

        return [$service->approveReplacement($service->startRepair($repair, $operator), $operator, 'Replace'), $original, $record];
    };

    [$repair, $original, $record] = $newRepair();
    $atCustomer = static function (SerializedInventoryUnit $unit) use ($record): SerializedInventoryUnit {
        $unit->forceFill(['custody_type' => SerializedCustodyType::Customer, 'custody_reference_id' => $record->customer_id, 'warehouse_id' => null])->save();

        return $unit;
    };

    $sameUnit = $original;
    $otherProduct = $atCustomer(ContinuityFixtures::warehouseUnit());
    $otherCustomer = ContinuityFixtures::warehouseUnit(ProductVariant::query()->findOrFail($original->product_variant_id));
    $otherCustomer->forceFill(['custody_type' => SerializedCustodyType::Customer, 'custody_reference_id' => CustomerProfile::factory()->create()->id])->save();

    expect(fn () => $service->recordReplacementReceived($repair, $sameUnit, $operator))->toThrow(ValidationException::class, 'different serialized unit')
        ->and(fn () => $service->recordReplacementReceived($repair, $otherProduct, $operator))->toThrow(ValidationException::class, 'same product')
        ->and(fn () => $service->recordReplacementReceived($repair, $otherCustomer, $operator))->toThrow(ValidationException::class, 'same customer custody');

    $good = $atCustomer(ContinuityFixtures::warehouseUnit(ProductVariant::query()->findOrFail($original->product_variant_id)));
    $service->recordReplacementReceived($repair, $good, $operator);

    [$second] = $newRepair();
    $second->serializedInventoryUnit->forceFill(['product_variant_id' => $good->product_variant_id])->save();
    $second->maintenanceRecord->update(['customer_id' => $record->customer_id]);

    expect(fn () => $service->recordReplacementReceived($second, $good, $operator))->toThrow(ValidationException::class, 'already replaces');
});

it("links the maintenance request's existing recovery claim and records how it was settled", function (): void {
    $repair = repairAt(ExternalRepairStatus::Requested);
    $operator = ContinuityFixtures::operator();
    $service = app(ExternalRepairService::class);
    $claim = WarrantyRecoveryClaim::factory()->create(['maintenance_record_id' => $repair->maintenance_record_id]);
    $foreign = WarrantyRecoveryClaim::factory()->create();

    expect(fn () => $service->recordRecoveryOutcome($repair, WarrantyRecoveryOutcome::CreditNote, $operator))->toThrow(ValidationException::class, 'Link a warranty recovery claim')
        ->and(fn () => $service->linkRecoveryClaim($repair, $foreign, $operator))->toThrow(ValidationException::class, 'different maintenance request');

    $linked = $service->linkRecoveryClaim($repair, $claim, $operator);

    expect($linked->warranty_recovery_claim_id)->toBe($claim->id)
        ->and($linked->fresh()->warrantyRecoveryClaim->is($claim))->toBeTrue();

    foreach (WarrantyRecoveryOutcome::cases() as $outcome) {
        expect($service->recordRecoveryOutcome($linked->fresh(), $outcome, $operator)->recovery_outcome)->toBe($outcome);
    }

    expect($claim->fresh()->recovery_outcome)->toBe(WarrantyRecoveryOutcome::Rejected)
        ->and(WarrantyRecoveryOutcome::ReplacementUnit->label())->toBe('Replacement unit');
});

it('blocks closing or cancelling a request while its supplier repair is open', function (): void {
    $repair = repairAt(ExternalRepairStatus::Requested);
    $operator = ContinuityFixtures::operator();
    $records = app(MaintenanceRecordService::class);
    $record = $repair->maintenanceRecord;
    $record->update(['status' => MaintenanceStatus::QualityAssurance]);

    expect(fn () => $records->transition($record->fresh(), MaintenanceStatus::Closed, $operator))->toThrow(InvalidStatusTransition::class, 'open supplier repair');

    app(ExternalRepairService::class)->cancel($repair, $operator, 'Not needed');
    $records->transition($record->fresh(), MaintenanceStatus::Closed, $operator);

    expect($record->fresh()->status)->toBe(MaintenanceStatus::Closed);
});

it('dispatches status and return milestones after the commit', function (): void {
    Event::fake([SupportContinuityMilestone::class]);
    $operator = ContinuityFixtures::operator();
    $service = app(ExternalRepairService::class);
    [$record, $unit, $supplier] = rmaScenario();

    $repair = $service->request($record, $supplier, $operator);
    $repair = $service->approve($repair, $operator);
    ContinuityFixtures::receiveIntoWarehouse($unit);
    $repair = $service->recordRepaired($service->startRepair($service->markReceivedBySupplier($service->ship($repair, $operator), $operator), $operator), $operator, 'Fixed');
    $service->returnToCompany($repair, ContinuityFixtures::warehouse()->id, StockCondition::Saleable, $operator);

    Event::assertDispatched(SupportContinuityMilestone::class, fn (SupportContinuityMilestone $event): bool => $event->key === NotificationEventKey::RmaStatusChanged && $event->subjectId === $repair->id);
    Event::assertDispatched(SupportContinuityMilestone::class, fn (SupportContinuityMilestone $event): bool => $event->key === NotificationEventKey::EquipmentReturnedFromSupplier);
    Event::assertDispatchedTimes(SupportContinuityMilestone::class, 7);
});

it('enforces RMA permissions per role and blocks everything while the flag is off', function (): void {
    $repair = repairAt(ExternalRepairStatus::Requested);
    $agent = User::factory()->admin()->create();
    $agent->assignRole('Support Agent');

    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');

    $service = app(ExternalRepairService::class);

    expect($agent->can('view', $repair))->toBeTrue()
        ->and($agent->can('update', $repair))->toBeFalse()
        ->and($reviewer->can('viewAny', MaintenanceExternalRepair::class))->toBeTrue()
        ->and($reviewer->can('create', MaintenanceExternalRepair::class))->toBeFalse()
        ->and(fn () => $service->approve($repair, $agent))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->request($repair->maintenanceRecord, Supplier::factory()->create(), $reviewer))->toThrow(AuthorizationException::class);

    config(['support.external_repair_enabled' => false]);

    expect(ContinuityFixtures::operator()->can('viewAny', MaintenanceExternalRepair::class))->toBeFalse()
        ->and(MaintenanceExternalRepair::query()->count())->toBe(1);
});

it('flags an open repair as overdue after its estimated return date', function (): void {
    $repair = repairAt(ExternalRepairStatus::Requested);

    expect($repair->isOverdue())->toBeFalse();

    $repair->update(['estimated_return_on' => now()->subDay()]);

    expect($repair->fresh()->isOverdue())->toBeTrue();

    $repair->update(['status' => ExternalRepairStatus::Cancelled]);

    expect($repair->fresh()->isOverdue())->toBeFalse();
});
