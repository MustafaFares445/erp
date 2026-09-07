<?php

declare(strict_types=1);

use App\Data\Support\MaintenanceScheduleData;
use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceIntervalType;
use App\Enums\MaintenanceStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\SerializedInventoryUnitStatus;
use App\Models\CustomerProfile;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Services\Support\MaintenanceRecordService;
use App\Services\Support\MaintenanceScheduleGenerator;
use App\Services\Support\MaintenanceScheduleService;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
    $unit = $overrides['serializedInventoryUnitId'] ?? SerializedInventoryUnit::factory()->create()->getKey();

    return new MaintenanceScheduleData(
        serializedInventoryUnitId: $unit,
        customerId: $overrides['customerId'] ?? CustomerProfile::factory()->create()->getKey(),
        name: $overrides['name'] ?? 'Quarterly service',
        intervalType: $overrides['intervalType'] ?? MaintenanceIntervalType::Months,
        intervalValue: $overrides['intervalValue'] ?? 3,
        leadTimeDays: $overrides['leadTimeDays'] ?? 7,
        firstDueOn: $overrides['firstDueOn'] ?? now()->addDays(5)->toDateString(),
        billingType: $overrides['billingType'] ?? MaintenanceBillingType::Unbilled,
        checklist: $overrides['checklist'] ?? null,
    );
}

it('generates a bounded set of occurrences when a schedule is created', function (): void {
    $manager = makeScheduleManager();

    $schedule = app(MaintenanceScheduleService::class)->create(
        makeScheduleData(['intervalType' => MaintenanceIntervalType::Months, 'intervalValue' => 1, 'firstDueOn' => now()->toDateString()]),
        $manager,
    );

    // 12 months / 1-month interval = 12 occurrences exactly at the bound.
    expect($schedule->occurrences()->count())->toBe(12)
        ->and($schedule->occurrences()->where('status', OccurrenceStatus::Pending->value)->count())->toBe(12)
        ->and($schedule->next_due_on->toDateString())->toBe($schedule->first_due_on->toDateString());
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

    // A second occurrence far outside the lead time must not be raised.
    $farOccurrence = $schedule->occurrences()->orderBy('due_on', 'desc')->first();
    expect($farOccurrence->due_on->gt(now()->addDays(7)))->toBeTrue();

    $generator = app(MaintenanceScheduleGenerator::class);

    $raisedFirstRun = $generator->raiseDue();
    expect($raisedFirstRun)->toBe(1);

    $dueOccurrence = $schedule->occurrences()->orderBy('due_on')->first()->fresh();
    expect($dueOccurrence->status)->toBe(OccurrenceStatus::Raised)
        ->and($dueOccurrence->maintenance_record_id)->not->toBeNull();

    // Idempotent: a second run the same day raises nothing further for this schedule.
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

    // Completion no longer extends the horizon once the schedule is inactive.
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
