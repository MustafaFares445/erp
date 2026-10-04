<?php

declare(strict_types=1);

use App\Enums\EquipmentLoanStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\CustomerProfile;
use App\Models\EquipmentLoan;
use App\Models\InventoryLot;
use App\Models\MaintenanceRecord;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Services\Support\EquipmentLoanService;
use App\Services\Support\Exceptions\InvalidStatusTransition;
use App\Services\Support\MaintenanceRecordService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\ContinuityFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ContinuityFixtures::seedPermissions();
    config(['support.loaner_equipment_enabled' => true]);
});

/** @return array{0: MaintenanceRecord, 1: SerializedInventoryUnit, 2: SerializedInventoryUnit} request, customer unit, loaner */
function loanScenario(): array
{
    [, $original, $record] = ContinuityFixtures::repairScenario();

    return [$record, $original, ContinuityFixtures::loanerFor($original)];
}

it('lists only available, saleable, warehouse-held units of the same product that are not on a loan', function (): void {
    [$record, $original, $loaner] = loanScenario();
    $service = app(EquipmentLoanService::class);

    $otherProduct = ContinuityFixtures::warehouseUnit();
    $damaged = ContinuityFixtures::loanerFor($original);
    $damaged->forceFill(['status' => SerializedInventoryUnitStatus::Damaged, 'stock_condition' => StockCondition::Damaged])->save();
    $quarantined = ContinuityFixtures::loanerFor($original);
    $quarantined->forceFill(['stock_condition' => StockCondition::Quarantine])->save();
    $lotted = ContinuityFixtures::loanerFor($original);
    $lotted->forceFill(['inventory_lot_id' => InventoryLot::factory()->create(['product_variant_id' => $lotted->product_variant_id])->id])->save();
    $booked = ContinuityFixtures::loanerFor($original);
    EquipmentLoan::factory()->create(['loaner_serialized_inventory_unit_id' => $booked->id]);

    expect($service->eligibleLoaners($record)->pluck('id')->all())->toBe([$loaner->id])
        ->and($service->eligibleLoaners($record)->first()->relationLoaded('productVariant'))->toBeTrue();

    $record->update(['serialized_inventory_unit_id' => null]);

    expect($service->eligibleLoaners($record->fresh()))->toBeEmpty();
});

it("reserves a loaner for the customer's request and records an audit entry", function (): void {
    [$record, $original, $loaner] = loanScenario();
    $operator = ContinuityFixtures::operator();

    $loan = app(EquipmentLoanService::class)->reserve($record, $loaner, $operator, now()->addDays(5), 'While in repair');

    expect($loan->status)->toBe(EquipmentLoanStatus::Reserved)
        ->and($loan->customer_id)->toBe($record->customer_id)
        ->and($loan->original_serialized_inventory_unit_id)->toBe($original->id)
        ->and($loan->loaner_serialized_inventory_unit_id)->toBe($loaner->id)
        ->and($loan->reserved_at)->not->toBeNull()
        ->and($loan->notes)->toBe('While in repair')
        ->and($loan->isOverdue())->toBeFalse()
        ->and($record->equipmentLoans()->count())->toBe(1)
        ->and($loan->maintenanceRecord->is($record))->toBeTrue()
        ->and($loan->customer->is(CustomerProfile::query()->find($record->customer_id)))->toBeTrue()
        ->and($loan->originalUnit->is($original))->toBeTrue()
        ->and($loan->loanerUnit->is($loaner))->toBeTrue()
        ->and(Activity::query()->where('description', 'support.loaner.reserved')->count())->toBe(1)
        // Reserving never touches custody.
        ->and($loaner->fresh()->custody_type)->toBe(SerializedCustodyType::Warehouse);
});

it("rejects loaners that are unavailable, incompatible, double-booked or the customer's own unit", function (): void {
    [$record, $original, $loaner] = loanScenario();
    $operator = ContinuityFixtures::operator();
    $service = app(EquipmentLoanService::class);

    $wrongProduct = ContinuityFixtures::warehouseUnit();
    $notAvailable = ContinuityFixtures::loanerFor($original);
    $notAvailable->forceFill(['custody_type' => SerializedCustodyType::Customer])->save();
    $damaged = ContinuityFixtures::loanerFor($original);
    $damaged->forceFill(['stock_condition' => StockCondition::Damaged])->save();

    expect(fn () => $service->reserve($record, $wrongProduct, $operator))->toThrow(ValidationException::class, 'same product')
        ->and(fn () => $service->reserve($record, $notAvailable, $operator))->toThrow(ValidationException::class, 'available and held in a warehouse')
        ->and(fn () => $service->reserve($record, $damaged, $operator))->toThrow(ValidationException::class, 'available and held in a warehouse')
        ->and(fn () => $service->reserve($record, $original, $operator))->toThrow(ValidationException::class, 'own equipment');

    $service->reserve($record, $loaner, $operator);

    $other = MaintenanceRecord::factory()->create(['customer_id' => $record->customer_id, 'serialized_inventory_unit_id' => $original->id]);

    expect(fn () => $service->reserve($record, ContinuityFixtures::loanerFor($original), $operator))->toThrow(ValidationException::class, 'already has an active loaner')
        ->and(fn () => $service->reserve($other, $loaner, $operator))->toThrow(ValidationException::class, 'already has an active loan');
});

it('rejects reserving for requests that are closed, unlinked or whose equipment is not with the customer', function (): void {
    [$record, $original, $loaner] = loanScenario();
    $operator = ContinuityFixtures::operator();
    $service = app(EquipmentLoanService::class);

    $record->update(['status' => MaintenanceStatus::Closed]);
    expect(fn () => $service->reserve($record, $loaner, $operator))->toThrow(ValidationException::class, 'closed or cancelled');

    $record->update(['status' => MaintenanceStatus::Open, 'serialized_inventory_unit_id' => null]);
    expect(fn () => $service->reserve($record, $loaner, $operator))->toThrow(ValidationException::class, 'serialized equipment');

    $record->update(['serialized_inventory_unit_id' => $original->id]);
    $original->forceFill(['custody_reference_id' => CustomerProfile::factory()->create()->id])->save();
    expect(fn () => $service->reserve($record, $loaner, $operator))->toThrow(ValidationException::class, 'custody');
});

it('issues a reserved loaner through Inventory and moves custody to the customer', function (): void {
    [$record, , $loaner] = loanScenario();
    $operator = ContinuityFixtures::operator();
    $service = app(EquipmentLoanService::class);
    $loan = $service->reserve($record, $loaner, $operator);

    $issued = $service->issue($loan, $operator, now()->addDays(5));
    $loaner->refresh();

    expect($issued->status)->toBe(EquipmentLoanStatus::Issued)
        ->and($issued->issued_at)->not->toBeNull()
        ->and($issued->condition_out)->toBe(StockCondition::Saleable)
        ->and($issued->issue_inventory_movement_id)->not->toBeNull()
        ->and($loaner->custody_type)->toBe(SerializedCustodyType::Customer)
        ->and((int) $loaner->custody_reference_id)->toBe($record->customer_id)
        ->and(Activity::query()->where('description', 'support.loaner.issued')->count())->toBe(1);

    expect(fn () => $service->issue($issued, $operator, now()->addDay()))->toThrow(ValidationException::class, 'Only a reserved loan');
});

it('needs a future expected return date to issue and keeps the reservation when issuing fails', function (): void {
    [$record, , $loaner] = loanScenario();
    $operator = ContinuityFixtures::operator();
    $service = app(EquipmentLoanService::class);
    $loan = $service->reserve($record, $loaner, $operator);

    expect(fn () => $service->issue($loan, $operator))->toThrow(ValidationException::class, 'expected return date')
        ->and(fn () => $service->issue($loan, $operator, now()->subDay()))->toThrow(ValidationException::class, 'expected return date');

    $record->update(['status' => MaintenanceStatus::Closed]);
    expect(fn () => $service->issue($loan, $operator, now()->addDay()))->toThrow(ValidationException::class, 'closed or cancelled');

    $record->update(['status' => MaintenanceStatus::Open]);
    $loaner->forceFill(['stock_condition' => StockCondition::Damaged])->save();

    expect(fn () => $service->issue($loan, $operator, now()->addDay()))->toThrow(DomainException::class, 'saleable')
        ->and($loan->fresh()->status)->toBe(EquipmentLoanStatus::Reserved);
});

it('never moves custody on Support permission alone', function (): void {
    [$record, , $loaner] = loanScenario();
    $supportOnly = ContinuityFixtures::supportOnly();
    $service = app(EquipmentLoanService::class);
    $loan = $service->reserve($record, $loaner, $supportOnly);

    expect(fn () => $service->issue($loan, $supportOnly, now()->addDays(3)))->toThrow(AuthorizationException::class)
        ->and($loan->fresh()->status)->toBe(EquipmentLoanStatus::Reserved)
        ->and($loaner->fresh()->custody_type)->toBe(SerializedCustodyType::Warehouse);

    $operator = ContinuityFixtures::operator();
    $issued = $service->issue($loan, $operator, now()->addDays(3));

    expect(fn () => $service->recordReturn($issued, $loaner->warehouse_id, StockCondition::Saleable, $supportOnly))->toThrow(AuthorizationException::class)
        ->and($issued->fresh()->status)->toBe(EquipmentLoanStatus::Issued);
});

it('records the return with the inspected condition, even after the request was closed', function (): void {
    foreach ([
        [StockCondition::Saleable, SerializedInventoryUnitStatus::Available],
        [StockCondition::Damaged, SerializedInventoryUnitStatus::Damaged],
        [StockCondition::Quarantine, SerializedInventoryUnitStatus::Available],
    ] as [$condition, $status]) {
        [$record, , $loaner] = loanScenario();
        $operator = ContinuityFixtures::operator();
        $service = app(EquipmentLoanService::class);
        $issued = $service->issue($service->reserve($record, $loaner, $operator), $operator, now()->addDays(2));
        $warehouse = ContinuityFixtures::warehouse();
        $record->update(['status' => MaintenanceStatus::Closed]);

        $returned = $service->recordReturn($issued, $warehouse->id, $condition, $operator, 'Inspected on return');
        $loaner->refresh();

        expect($returned->status)->toBe(EquipmentLoanStatus::Returned)
            ->and($returned->condition_in)->toBe($condition)
            ->and($returned->returned_at)->not->toBeNull()
            ->and($returned->return_inventory_movement_id)->not->toBeNull()
            ->and($returned->notes)->toBe('Inspected on return')
            ->and($loaner->custody_type)->toBe(SerializedCustodyType::Warehouse)
            ->and($loaner->warehouse_id)->toBe($warehouse->id)
            ->and($loaner->status)->toBe($status)
            ->and(fn () => $service->recordReturn($returned, $warehouse->id, $condition, $operator))->toThrow(ValidationException::class, 'Only an issued loan');
    }

    expect(Activity::query()->where('description', 'support.loaner.returned')->count())->toBe(3);
});

it('cancels only reserved loans, with a reason', function (): void {
    [$record, , $loaner] = loanScenario();
    $operator = ContinuityFixtures::operator();
    $service = app(EquipmentLoanService::class);
    $loan = $service->reserve($record, $loaner, $operator);

    expect(fn () => $service->cancel($loan, $operator, ' '))->toThrow(ValidationException::class, 'reason is required');

    $cancelled = $service->cancel($loan, $operator, 'Customer collected their unit');

    expect($cancelled->status)->toBe(EquipmentLoanStatus::Cancelled)
        ->and($cancelled->notes)->toBe('Customer collected their unit')
        ->and($service->eligibleLoaners($record)->pluck('id')->all())->toContain($loaner->id);

    $second = $service->reserve($record, $loaner, $operator);
    $issued = $service->issue($second, $operator, now()->addDay());

    expect(fn () => $service->cancel($issued, $operator, 'Too late'))->toThrow(ValidationException::class, 'must be returned');
});

it('blocks closing or cancelling a request while a loaner is reserved or issued', function (): void {
    [$record, , $loaner] = loanScenario();
    $operator = ContinuityFixtures::operator();
    $loans = app(EquipmentLoanService::class);
    $records = app(MaintenanceRecordService::class);
    $loan = $loans->reserve($record, $loaner, $operator);

    expect(fn () => $records->transition($record, MaintenanceStatus::Cancelled, $operator))->toThrow(InvalidStatusTransition::class, 'active loaner');

    $loan = $loans->issue($loan, $operator, now()->addDay());
    $record->update(['status' => MaintenanceStatus::QualityAssurance]);

    expect(fn () => $records->transition($record->fresh(), MaintenanceStatus::Closed, $operator))->toThrow(InvalidStatusTransition::class, 'active loaner');

    $loans->recordReturn($loan, $loaner->warehouse_id ?? ContinuityFixtures::warehouse()->id, StockCondition::Saleable, $operator);
    $records->transition($record->fresh(), MaintenanceStatus::Closed, $operator);

    expect($record->fresh()->status)->toBe(MaintenanceStatus::Closed);
});

it('flags an issued loan as overdue only after the expected return date', function (): void {
    $loan = EquipmentLoan::factory()->issued()->create();

    expect($loan->isOverdue())->toBeFalse();

    $loan->update(['expected_return_at' => now()->subHour()]);

    expect($loan->fresh()->isOverdue())->toBeTrue();

    $loan->update(['status' => EquipmentLoanStatus::Returned]);

    expect($loan->fresh()->isOverdue())->toBeFalse()
        ->and(EquipmentLoanStatus::Reserved->isActive())->toBeTrue()
        ->and(EquipmentLoanStatus::Returned->isActive())->toBeFalse()
        ->and(EquipmentLoanStatus::activeValues())->toBe(['reserved', 'issued']);
});

it('enforces loaner permissions per role and blocks everything while the flag is off', function (): void {
    [$record, , $loaner] = loanScenario();
    $operator = ContinuityFixtures::operator();
    $service = app(EquipmentLoanService::class);
    $loan = $service->reserve($record, $loaner, $operator);
    $agent = User::factory()->admin()->create();
    $agent->assignRole('Support Agent');

    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');

    expect($agent->can('viewAny', EquipmentLoan::class))->toBeTrue()
        ->and($agent->can('create', EquipmentLoan::class))->toBeFalse()
        ->and($reviewer->can('view', $loan))->toBeTrue()
        ->and($reviewer->can('update', $loan))->toBeFalse()
        ->and(fn () => $service->reserve($record, ContinuityFixtures::loanerFor($loaner), $agent))->toThrow(AuthorizationException::class);

    config(['support.loaner_equipment_enabled' => false]);

    expect($operator->can('viewAny', EquipmentLoan::class))->toBeFalse()
        ->and($operator->can('update', $loan))->toBeFalse()
        ->and(EquipmentLoan::query()->count())->toBe(1);
});

it('uses the loaner product of the same family for compatibility, not just the same variant', function (): void {
    [, $original] = loanScenario();
    $sameFamily = ProductVariant::factory()->create(['product_id' => $original->productVariant->product_id]);

    expect(ContinuityFixtures::warehouseUnit($sameFamily)->productVariant->product_id)->toBe($original->productVariant->product_id);
});
