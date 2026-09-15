<?php

declare(strict_types=1);

use App\Data\Support\MaintenanceScheduleData;
use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceIntervalType;
use App\Enums\MaintenanceStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Filament\Resources\MaintenanceSchedules\Pages\CreateMaintenanceSchedule;
use App\Filament\Resources\MaintenanceSchedules\Pages\ViewMaintenanceSchedule;
use App\Models\CustomerProfile;
use App\Models\MaintenanceSchedule;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Services\Support\MaintenanceRecordService;
use App\Services\Support\MaintenanceScheduleGenerator;
use App\Services\Support\MaintenanceScheduleService;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
});

function makeScheduleManager(): User
{
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    return $manager;
}

function makeScheduleData(array $overrides = []): MaintenanceScheduleData
{
    $customerId = $overrides['customerId'] ?? CustomerProfile::factory()->create()->getKey();
    $unitId = $overrides['serializedInventoryUnitId'] ?? null;

    if (is_numeric($unitId)) {
        $unit = SerializedInventoryUnit::query()->findOrFail((int) $unitId);
        $unit->forceFill([
            'custody_type' => SerializedCustodyType::Customer,
            'custody_reference_id' => $customerId,
        ])->save();
    } else {
        $unit = SerializedInventoryUnit::factory()->create([
            'custody_type' => SerializedCustodyType::Customer,
            'custody_reference_id' => $customerId,
        ]);
        $unitId = $unit->getKey();
    }

    return new MaintenanceScheduleData(
        serializedInventoryUnitId: (int) $unitId,
        customerId: (int) $customerId,
        name: $overrides['name'] ?? 'Quarterly service',
        intervalType: $overrides['intervalType'] ?? MaintenanceIntervalType::Months,
        intervalValue: $overrides['intervalValue'] ?? 3,
        leadTimeDays: $overrides['leadTimeDays'] ?? 7,
        firstDueOn: $overrides['firstDueOn'] ?? now()->addDays(5)->toDateString(),
        billingType: $overrides['billingType'] ?? MaintenanceBillingType::Unbilled,
        checklist: $overrides['checklist'] ?? null,
    );
}

it('creates a schedule when numeric form values arrive as floats', function (): void {
    $manager = makeScheduleManager();
    $customer = CustomerProfile::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $customer->getKey(),
    ]);

    Livewire::actingAs($manager)
        ->test(CreateMaintenanceSchedule::class)
        ->fillForm([
            'customer_id' => $customer->getKey(),
            'serialized_inventory_unit_id' => $unit->getKey(),
            'name' => 'Quarterly printer service',
            'interval_type' => MaintenanceIntervalType::Months->value,
            'interval_value' => 3.0,
            'lead_time_days' => 7.0,
            'first_due_on' => now()->addWeek()->toDateString(),
            'billing_type' => MaintenanceBillingType::Unbilled->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(MaintenanceSchedule::query()->where('name', 'Quarterly printer service')->exists())->toBeTrue();
});

it('renders a schedule detail page with enum-cast occurrence statuses', function (): void {
    $manager = makeScheduleManager();
    $schedule = app(MaintenanceScheduleService::class)->create(makeScheduleData(), $manager);

    Livewire::actingAs($manager)
        ->test(ViewMaintenanceSchedule::class, ['record' => $schedule->getRouteKey()])
        ->assertSuccessful();
});

it('generates a bounded set of occurrences when a schedule is created', function (): void {
    $manager = makeScheduleManager();

    $schedule = app(MaintenanceScheduleService::class)->create(
        makeScheduleData(['intervalType' => MaintenanceIntervalType::Months, 'intervalValue' => 1, 'firstDueOn' => now()->toDateString()]),
        $manager,
    );

    expect($schedule->occurrences()->count())->toBe(12)
        ->and($schedule->occurrences()->where('status', OccurrenceStatus::Pending->value)->count())->toBe(12)
        ->and($schedule->next_due_on->toDateString())->toBe($schedule->first_due_on->toDateString());
});

it('rejects preventive maintenance equipment that is not in the selected customer custody', function (): void {
    $manager = makeScheduleManager();
    $customer = CustomerProfile::factory()->create();
    $otherCustomer = CustomerProfile::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $otherCustomer->getKey(),
    ]);

    $data = new MaintenanceScheduleData(
        serializedInventoryUnitId: (int) $unit->getKey(),
        customerId: (int) $customer->getKey(),
        name: 'Invalid customer equipment',
        intervalType: MaintenanceIntervalType::Months,
        intervalValue: 1,
        leadTimeDays: 7,
        firstDueOn: now()->addWeek()->toDateString(),
        billingType: MaintenanceBillingType::Unbilled,
    );

    expect(fn () => app(MaintenanceScheduleService::class)->create($data, $manager))
        ->toThrow(ValidationException::class);
});

it('generates exactly one occurrence for a usage-hours schedule, which has no calendar arithmetic', function (): void {
    $manager = makeScheduleManager();

    $schedule = app(MaintenanceScheduleService::class)->create(
        makeScheduleData(['intervalType' => MaintenanceIntervalType::UsageHours, 'intervalValue' => 500]),
        $manager,
    );

    expect($schedule->occurrences()->count())->toBe(1);
});

it('raises only occurrences inside the lead time, and is idempotent across two runs the same day', function (): void {
    $manager = makeScheduleManager();

    $schedule = app(MaintenanceScheduleService::class)->create(
        makeScheduleData([
            'leadTimeDays' => 7,
            'firstDueOn' => now()->addDays(5)->toDateString(),
            'intervalType' => MaintenanceIntervalType::Months,
            'intervalValue' => 1,
        ]),
        $manager,
    );

    $farOccurrence = $schedule->occurrences()->orderBy('due_on', 'desc')->first();
    expect($farOccurrence->due_on->gt(now()->addDays(7)))->toBeTrue();

    $generator = app(MaintenanceScheduleGenerator::class);

    $raisedFirstRun = $generator->raiseDue();
    expect($raisedFirstRun)->toBe(1);

    $dueOccurrence = $schedule->occurrences()->orderBy('due_on')->first()->fresh();
    expect($dueOccurrence->status)->toBe(OccurrenceStatus::Raised)
        ->and($dueOccurrence->maintenance_record_id)->not->toBeNull();

    $raisedSecondRun = $generator->raiseDue();
    expect($raisedSecondRun)->toBe(0);
});

it('marks a past-due, unraised occurrence as missed', function (): void {
    $manager = makeScheduleManager();

    $schedule = app(MaintenanceScheduleService::class)->create(
        makeScheduleData(['firstDueOn' => now()->subDays(10)->toDateString(), 'leadTimeDays' => 1]),
        $manager,
    );

    $missed = app(MaintenanceScheduleGenerator::class)->markMissed();

    expect($missed)->toBeGreaterThanOrEqual(1);

    $occurrence = $schedule->occurrences()->orderBy('due_on')->first()->fresh();
    expect($occurrence->status)->toBe(OccurrenceStatus::Missed);
});

it('completes the occurrence, updates last_completed_on, and extends the horizon when the linked job closes', function (): void {
    $manager = makeScheduleManager();

    $schedule = app(MaintenanceScheduleService::class)->create(
        makeScheduleData([
            'firstDueOn' => now()->toDateString(),
            'leadTimeDays' => 30,
            'intervalType' => MaintenanceIntervalType::Months,
            'intervalValue' => 1,
        ]),
        $manager,
    );

    $countBefore = $schedule->occurrences()->count();

    app(MaintenanceScheduleGenerator::class)->raiseDue();

    $occurrence = $schedule->occurrences()->orderBy('due_on')->first()->fresh();
    expect($occurrence->status)->toBe(OccurrenceStatus::Raised);

    $record = $occurrence->maintenanceRecord;
    $recordService = app(MaintenanceRecordService::class);
    $recordService->transition($record, MaintenanceStatus::InProgress, $manager);
    $recordService->transition($record, MaintenanceStatus::Closed, $manager);

    $occurrence->refresh();
    $schedule->refresh();

    expect($occurrence->status)->toBe(OccurrenceStatus::Completed)
        ->and($occurrence->completed_at)->not->toBeNull()
        ->and($schedule->last_completed_on->toDateString())->toBe($occurrence->due_on->toDateString())
        ->and($schedule->occurrences()->count())->toBe($countBefore + 1);
});

it('skips an occurrence, recording the reason without creating a maintenance record', function (): void {
    $manager = makeScheduleManager();

    $schedule = app(MaintenanceScheduleService::class)->create(makeScheduleData(), $manager);
    $occurrence = $schedule->occurrences()->orderBy('due_on')->first();

    $skipped = app(MaintenanceScheduleGenerator::class)->skip($occurrence, $manager, 'Customer requested a later date');

    expect($skipped->status)->toBe(OccurrenceStatus::Skipped)
        ->and($skipped->skipped_reason)->toBe('Customer requested a later date')
        ->and($skipped->maintenance_record_id)->toBeNull();
});

it('deactivates a schedule, stopping generation without deleting its occurrence history', function (): void {
    $manager = makeScheduleManager();

    $schedule = app(MaintenanceScheduleService::class)->create(
        makeScheduleData(['firstDueOn' => now()->toDateString(), 'leadTimeDays' => 30]),
        $manager,
    );

    app(MaintenanceScheduleGenerator::class)->raiseDue();
    $occurrenceCountBeforeDeactivation = $schedule->occurrences()->count();

    $deactivated = app(MaintenanceScheduleService::class)->deactivate($schedule, $manager);

    expect($deactivated->is_active)->toBeFalse()
        ->and($schedule->occurrences()->count())->toBe($occurrenceCountBeforeDeactivation);

    $record = $schedule->occurrences()->orderBy('due_on')->first()->maintenanceRecord;
    app(MaintenanceRecordService::class)->transition($record, MaintenanceStatus::InProgress, $manager);
    app(MaintenanceRecordService::class)->transition($record, MaintenanceStatus::Closed, $manager);

    expect($schedule->occurrences()->count())->toBe($occurrenceCountBeforeDeactivation);
});

it('stops generating occurrences for a schedule whose serialized unit is disposed', function (): void {
    $manager = makeScheduleManager();
    $unit = SerializedInventoryUnit::factory()->create(['status' => SerializedInventoryUnitStatus::Disposed]);

    $schedule = app(MaintenanceScheduleService::class)->create(
        makeScheduleData([
            'serializedInventoryUnitId' => $unit->getKey(),
            'firstDueOn' => now()->toDateString(),
            'leadTimeDays' => 30,
        ]),
        $manager,
    );

    $raised = app(MaintenanceScheduleGenerator::class)->raiseDue();

    expect($raised)->toBe(0)
        ->and($schedule->occurrences()->where('status', OccurrenceStatus::Pending->value)->count())->toBe($schedule->occurrences()->count());
});
