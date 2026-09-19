<?php

declare(strict_types=1);

use App\Data\Support\MaintenanceScheduleData;
use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceIntervalType;
use App\Enums\OccurrenceStatus;
use App\Enums\SerializedCustodyType;
use App\Models\CustomerProfile;
use App\Models\MaintenanceSchedule;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Services\Support\MaintenanceScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function maintenanceScheduleCoverageData(
    SerializedInventoryUnit $unit,
    ?CustomerProfile $customer,
    MaintenanceIntervalType $interval = MaintenanceIntervalType::Months,
    int $intervalValue = 1,
): MaintenanceScheduleData {
    return new MaintenanceScheduleData(
        serializedInventoryUnitId: (int) $unit->getKey(),
        customerId: $customer?->getKey(),
        name: 'Coverage preventive service',
        intervalType: $interval,
        intervalValue: $intervalValue,
        leadTimeDays: 7,
        firstDueOn: today()->addMonth()->toDateString(),
        billingType: MaintenanceBillingType::Unbilled,
        checklist: [['task' => 'Inspect']],
    );
}

function maintenanceScheduleCoverageCustomerUnit(CustomerProfile $customer): SerializedInventoryUnit
{
    return SerializedInventoryUnit::factory()->create([
        'warehouse_id' => null,
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_type' => CustomerProfile::class,
        'custody_reference_id' => $customer->getKey(),
    ]);
}

it('creates regenerates and deactivates a preventive maintenance schedule', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->create();
    $customer = CustomerProfile::factory()->create();
    $unit = maintenanceScheduleCoverageCustomerUnit($customer);
    $service = app(MaintenanceScheduleService::class);

    $schedule = $service->create(maintenanceScheduleCoverageData($unit, $customer), $actor);
    expect($schedule)->toBeInstanceOf(MaintenanceSchedule::class)
        ->and($schedule->customer_id)->toBe($customer->getKey())
        ->and($schedule->occurrences()->count())->toBe(12)
        ->and($schedule->next_due_on)->not->toBeNull();
    $updatedData = maintenanceScheduleCoverageData($unit, $customer, MaintenanceIntervalType::Weeks, 2);
    $schedule = $service->update($schedule, $updatedData, $actor);
    expect($schedule->interval_type)->toBe(MaintenanceIntervalType::Weeks)
        ->and($schedule->occurrences()->where('status', OccurrenceStatus::Pending->value)->count())->toBeGreaterThan(0);

    $sameIntervalData = maintenanceScheduleCoverageData($unit, $customer, MaintenanceIntervalType::Weeks, 2);
    $sameIntervalData = new MaintenanceScheduleData(
        serializedInventoryUnitId: $sameIntervalData->serializedInventoryUnitId,
        customerId: $sameIntervalData->customerId,
        name: 'Renamed coverage schedule',
        intervalType: $sameIntervalData->intervalType,
        intervalValue: $sameIntervalData->intervalValue,
        leadTimeDays: 10,
        firstDueOn: $sameIntervalData->firstDueOn,
        billingType: MaintenanceBillingType::Unbilled,
        checklist: $sameIntervalData->checklist,
    );
    $schedule = $service->update($schedule, $sameIntervalData, $actor);
    expect($schedule->name)->toBe('Renamed coverage schedule');

    $schedule = $service->deactivate($schedule, $actor);
    expect($schedule->is_active)->toBeFalse();
});

it('validates the schedule customer and serialized equipment custody', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->create();
    $customer = CustomerProfile::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Warehouse,
        'custody_reference_id' => null,
    ]);
    $service = app(MaintenanceScheduleService::class);

    expect(fn () => $service->create(maintenanceScheduleCoverageData($unit, null), $actor))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->create(maintenanceScheduleCoverageData($unit, $customer), $actor))
        ->toThrow(ValidationException::class);
});

it('handles usage-hour horizons duplicate occurrences and empty pending horizons', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->create();
    $customer = CustomerProfile::factory()->create();
    $unit = maintenanceScheduleCoverageCustomerUnit($customer);
    $service = app(MaintenanceScheduleService::class);

    $schedule = $service->create(
        maintenanceScheduleCoverageData($unit, $customer, MaintenanceIntervalType::UsageHours, 100),
        $actor,
    );
    expect($schedule->occurrences()->count())->toBe(1);

    $due = $schedule->first_due_on->copy();
    $service->ensureOccurrence($schedule, $due);
    expect($schedule->occurrences()->whereDate('due_on', $due)->count())->toBe(1);

    $schedule->occurrences()->update(['status' => OccurrenceStatus::Completed->value]);
    $before = $schedule->next_due_on?->toDateString();
    $service->refreshNextDueOn($schedule);
    expect($schedule->refresh()->next_due_on?->toDateString())->toBe($before);
});
