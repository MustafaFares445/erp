<?php

declare(strict_types=1);

use App\Enums\CalibrationMeasurementResult;
use App\Enums\CalibrationResult;
use App\Enums\MaintenanceIntervalType;
use App\Enums\MaintenanceKind;
use App\Enums\MaintenanceStatus;
use App\Enums\SerializedCustodyType;
use App\Events\EquipmentCalibrationMilestone;
use App\Models\CustomerProfile;
use App\Models\EquipmentCalibration;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceSchedule;
use App\Models\MaintenanceScheduleOccurrence;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Services\Support\EquipmentCalibrationService;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CalibrationFixtures;
use Tests\Support\InstallationFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
});

it('treats calibration as its own maintenance kind that may recur on a schedule', function (): void {
    expect(MaintenanceKind::from('calibration'))->toBe(MaintenanceKind::Calibration)
        ->and(MaintenanceKind::Calibration->label())->toBe('Calibration')
        ->and(MaintenanceKind::Calibration->getLabel())->toBe('Calibration')
        ->and(MaintenanceKind::scheduleKinds())->toBe([MaintenanceKind::Preventive, MaintenanceKind::Inspection, MaintenanceKind::Calibration]);
});

it('starts a calibration with its measurement template and records an audit entry', function (): void {
    [, $unit, $record] = CalibrationFixtures::scenario();
    $manager = InstallationFixtures::manager();

    $calibration = CalibrationFixtures::started($record, $manager);

    expect($calibration->serialized_inventory_unit_id)->toBe($unit->id)
        ->and($calibration->isFinished())->toBeFalse()
        ->and($calibration->measurements)->toHaveCount(2)
        ->and($calibration->measurements->first()->result)->toBe(CalibrationMeasurementResult::Pending)
        ->and($calibration->measurements->first()->minimum_value)->toBe('940.0000')
        ->and($calibration->measurements->last()->is_required)->toBeFalse()
        ->and($record->fresh()->calibration?->is($calibration))->toBeTrue()
        ->and($calibration->measurements->first()->calibration->is($calibration))->toBeTrue()
        ->and(Activity::query()->where('description', 'support.calibration.started')->count())->toBe(1);
});

it("rejects a calibration on the wrong kind of request, missing equipment, or another customer's equipment", function (): void {
    $manager = InstallationFixtures::manager();
    $service = app(EquipmentCalibrationService::class);
    $data = ['measurements' => CalibrationFixtures::measurements()];

    [, , $record] = CalibrationFixtures::scenario();
    $record->update(['maintenance_kind' => MaintenanceKind::Corrective]);
    expect(fn () => $service->start($record, $manager, $data))->toThrow(ValidationException::class, 'calibration maintenance request');

    [, , $unlinked] = CalibrationFixtures::scenario();
    $unlinked->update(['serialized_inventory_unit_id' => null]);
    expect(fn () => $service->start($unlinked, $manager, $data))->toThrow(ValidationException::class, 'serialized equipment');

    [, $unit, $foreign] = CalibrationFixtures::scenario();
    $unit->update(['custody_reference_id' => CustomerProfile::factory()->create()->getKey()]);
    expect(fn () => $service->start($foreign, $manager, $data))->toThrow(ValidationException::class, 'custody');

    $unit->update(['custody_type' => SerializedCustodyType::Warehouse]);
    expect(fn () => $service->start($foreign, $manager, $data))->toThrow(ValidationException::class, 'custody');

    expect(EquipmentCalibration::query()->count())->toBe(0);
});

it('rejects starting on a closed or cancelled request and starting twice', function (): void {
    $manager = InstallationFixtures::manager();
    $service = app(EquipmentCalibrationService::class);
    $data = ['measurements' => CalibrationFixtures::measurements()];

    foreach ([MaintenanceStatus::Closed, MaintenanceStatus::Cancelled] as $status) {
        [, , $record] = CalibrationFixtures::scenario();
        $record->update(['status' => $status]);

        expect(fn () => $service->start($record, $manager, $data))->toThrow(ValidationException::class, 'closed or cancelled');
    }

    [, , $record] = CalibrationFixtures::scenario();
    $service->start($record, $manager, $data);

    expect(fn () => $service->start($record, $manager, $data))->toThrow(ValidationException::class, 'open calibration already exists');
});

it('blocks a second open calibration of the same equipment on another request', function (): void {
    $manager = InstallationFixtures::manager();
    [$customer, $unit, $record] = CalibrationFixtures::scenario();
    CalibrationFixtures::started($record, $manager);

    $other = MaintenanceRecord::factory()->create([
        'customer_id' => $customer->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
        'maintenance_kind' => MaintenanceKind::Calibration,
    ]);

    expect(fn (): EquipmentCalibration => CalibrationFixtures::started($other, $manager))->toThrow(ValidationException::class, 'open calibration already exists');

    // Once the first calibration is finished, the equipment may be calibrated again.
    app(EquipmentCalibrationService::class)->fail($record->calibration, $manager, 'Out of range');

    expect(CalibrationFixtures::started($other, $manager)->isFinished())->toBeFalse();
});

it('validates the measurement template', function (): void {
    $manager = InstallationFixtures::manager();
    $service = app(EquipmentCalibrationService::class);

    foreach ([
        [[], 'at least one measurement'],
        [[['label' => '  ', 'minimum_value' => '1']], 'needs a label'],
        [[['label' => 'Pressure']], 'minimum or maximum'],
        [[['label' => 'Pressure', 'minimum_value' => '9', 'maximum_value' => '3']], 'cannot exceed'],
        [[['label' => 'Pressure', 'minimum_value' => '1'], ['label' => 'Pressure', 'maximum_value' => '2']], 'must be unique'],
    ] as [$measurements, $message]) {
        [, , $record] = CalibrationFixtures::scenario();

        expect(fn () => $service->start($record, $manager, ['measurements' => $measurements]))->toThrow(ValidationException::class, $message);
    }

    [, , $record] = CalibrationFixtures::scenario();
    $calibration = $service->start($record, $manager, ['measurements' => [['label' => 'Vacuum level', 'maximum_value' => '0.5', 'unit' => 'mbar']]]);

    expect($calibration->measurements->sole()->measurement_key)->toBe('vacuum_level');
});

it('evaluates each measurement against its limits', function (): void {
    $manager = InstallationFixtures::manager();
    [, , $record] = CalibrationFixtures::scenario();
    $calibration = CalibrationFixtures::started($record, $manager);
    $service = app(EquipmentCalibrationService::class);

    foreach ([['943', CalibrationMeasurementResult::Passed], ['940', CalibrationMeasurementResult::Passed], ['960', CalibrationMeasurementResult::Passed], ['939.99', CalibrationMeasurementResult::Failed], ['960.5', CalibrationMeasurementResult::Failed]] as [$actual, $expected]) {
        $measurement = $service->recordMeasurement($calibration, 'furnace_temperature', $actual, $manager, 'checked');

        expect($measurement->result)->toBe($expected)
            ->and($measurement->isRecorded())->toBeTrue()
            ->and($measurement->notes)->toBe('checked');
    }

    expect($service->recordMeasurement($calibration, 'uniformity', '2', $manager)->result)->toBe(CalibrationMeasurementResult::Passed);
});

it('rejects unknown measurements, non-numeric values and measurements after the calibration finished', function (): void {
    $manager = InstallationFixtures::manager();
    [, , $record] = CalibrationFixtures::scenario();
    $calibration = CalibrationFixtures::started($record, $manager);
    $service = app(EquipmentCalibrationService::class);

    expect(fn () => $service->recordMeasurement($calibration, 'unknown', '1', $manager))->toThrow(ValidationException::class, 'Unknown calibration measurement')
        ->and(fn () => $service->recordMeasurement($calibration, 'furnace_temperature', 'hot', $manager))->toThrow(ValidationException::class, 'must be a number');

    $service->fail($calibration, $manager, 'Unstable');

    expect(fn () => $service->recordMeasurement($calibration, 'furnace_temperature', '945', $manager))->toThrow(ValidationException::class, 'already been recorded');
});

it('completes a calibration only when every required measurement is recorded and in tolerance', function (): void {
    $manager = InstallationFixtures::manager();
    [, , $record] = CalibrationFixtures::scenario();
    $calibration = CalibrationFixtures::started($record, $manager);
    $service = app(EquipmentCalibrationService::class);

    expect(fn () => $service->complete($calibration, $manager))->toThrow(ValidationException::class, 'required measurement must be recorded');

    $service->recordMeasurement($calibration, 'furnace_temperature', '990', $manager);
    expect(fn () => $service->complete($calibration, $manager))->toThrow(ValidationException::class, 'out of tolerance');

    // An optional measurement that fails does not block a calibration whose mandatory ones pass.
    $service->recordMeasurement($calibration, 'furnace_temperature', '951', $manager);
    $service->recordMeasurement($calibration, 'uniformity', '9', $manager);

    $completed = $service->complete($calibration, $manager, CalibrationResult::PassedWithAdjustment);

    expect($completed->result)->toBe(CalibrationResult::PassedWithAdjustment)
        ->and($completed->calibrated_at)->not->toBeNull()
        ->and($completed->isFinished())->toBeTrue()
        ->and(Activity::query()->where('description', 'support.calibration.completed')->count())->toBe(1);
});

it('refuses to complete a calibration without measurements or with a failed result', function (): void {
    $manager = InstallationFixtures::manager();
    [, , $record] = CalibrationFixtures::scenario();
    $calibration = CalibrationFixtures::started($record, $manager);
    $service = app(EquipmentCalibrationService::class);

    expect(fn () => $service->complete($calibration, $manager, CalibrationResult::Failed))->toThrow(ValidationException::class, 'failure reason');

    $calibration->measurements()->delete();

    expect(fn () => $service->complete($calibration->refresh(), $manager))->toThrow(ValidationException::class, 'at least one measurement');
});

it('rejects completing against equipment that no longer matches the request', function (): void {
    $manager = InstallationFixtures::manager();
    [, , $record] = CalibrationFixtures::scenario();
    $calibration = CalibrationFixtures::started($record, $manager);
    CalibrationFixtures::measure($calibration, $manager);

    $record->update(['serialized_inventory_unit_id' => SerializedInventoryUnit::factory()->create()->getKey()]);

    expect(fn () => app(EquipmentCalibrationService::class)->complete($calibration, $manager))->toThrow(ValidationException::class, 'does not match');
    expect(fn () => app(EquipmentCalibrationService::class)->fail($calibration, $manager, 'x'))->toThrow(ValidationException::class, 'does not match');
});

it('stores the next due date explicitly or from the equipment calibration schedule', function (): void {
    $manager = InstallationFixtures::manager();
    $service = app(EquipmentCalibrationService::class);

    [, , $explicit] = CalibrationFixtures::scenario();
    $calibration = CalibrationFixtures::started($explicit, $manager);
    CalibrationFixtures::measure($calibration, $manager);
    $due = now()->addMonths(3)->startOfDay();
    expect($service->complete($calibration, $manager, CalibrationResult::Passed, null, $due)->next_calibration_due_on->toDateString())->toBe($due->toDateString());

    // Schedule linked through the occurrence that raised the request.
    [$customer, $unit, $raised] = CalibrationFixtures::scenario();
    $schedule = MaintenanceSchedule::factory()->create([
        'serialized_inventory_unit_id' => $unit->getKey(),
        'customer_id' => $customer->getKey(),
        'maintenance_kind' => MaintenanceKind::Calibration,
        'interval_type' => MaintenanceIntervalType::Months,
        'interval_value' => 6,
        'created_by' => $manager->getKey(),
    ]);
    MaintenanceScheduleOccurrence::query()->forceCreate([
        'maintenance_schedule_id' => $schedule->getKey(),
        'due_on' => now()->toDateString(),
        'status' => 'raised',
        'maintenance_record_id' => $raised->getKey(),
    ]);
    $calibration = CalibrationFixtures::started($raised, $manager);
    CalibrationFixtures::measure($calibration, $manager);
    $completed = $service->complete($calibration, $manager, CalibrationResult::Passed, now());

    expect($completed->next_calibration_due_on->toDateString())->toBe(now()->startOfDay()->addMonthsNoOverflow(6)->toDateString());

    // Ad-hoc request: the unit's active calibration schedule still drives the next date.
    [$customer, $unit, $adhoc] = CalibrationFixtures::scenario();
    MaintenanceSchedule::factory()->create([
        'serialized_inventory_unit_id' => $unit->getKey(),
        'customer_id' => $customer->getKey(),
        'maintenance_kind' => MaintenanceKind::Calibration,
        'interval_type' => MaintenanceIntervalType::Weeks,
        'interval_value' => 2,
        'created_by' => $manager->getKey(),
    ]);
    $calibration = CalibrationFixtures::started($adhoc, $manager);
    CalibrationFixtures::measure($calibration, $manager);

    expect($service->complete($calibration, $manager, CalibrationResult::Passed, now())->next_calibration_due_on->toDateString())
        ->toBe(now()->startOfDay()->addWeeks(2)->toDateString());
});

it('leaves the next due date empty without a usable schedule and rejects one before the calibration date', function (): void {
    $manager = InstallationFixtures::manager();
    $service = app(EquipmentCalibrationService::class);

    [, , $none] = CalibrationFixtures::scenario();
    $calibration = CalibrationFixtures::started($none, $manager);
    CalibrationFixtures::measure($calibration, $manager);
    expect($service->complete($calibration, $manager)->next_calibration_due_on)->toBeNull();

    [$customer, $unit, $usage] = CalibrationFixtures::scenario();
    MaintenanceSchedule::factory()->create([
        'serialized_inventory_unit_id' => $unit->getKey(),
        'customer_id' => $customer->getKey(),
        'maintenance_kind' => MaintenanceKind::Calibration,
        'interval_type' => MaintenanceIntervalType::UsageHours,
        'interval_value' => 500,
        'created_by' => $manager->getKey(),
    ]);
    $calibration = CalibrationFixtures::started($usage, $manager);
    CalibrationFixtures::measure($calibration, $manager);
    expect($service->complete($calibration, $manager)->next_calibration_due_on)->toBeNull();

    [, , $past] = CalibrationFixtures::scenario();
    $calibration = CalibrationFixtures::started($past, $manager);
    CalibrationFixtures::measure($calibration, $manager);
    expect(fn () => $service->complete($calibration, $manager, CalibrationResult::Passed, now(), now()->subDay()))->toThrow(ValidationException::class, 'after the calibration date');
});

it('fails a calibration with a reason, optionally raising a follow-up repair request', function (): void {
    $manager = InstallationFixtures::manager();
    $service = app(EquipmentCalibrationService::class);

    [, , $record] = CalibrationFixtures::scenario();
    $calibration = CalibrationFixtures::started($record, $manager);

    expect(fn () => $service->fail($calibration, $manager, '  '))->toThrow(ValidationException::class, 'reason is required');

    $failed = $service->fail($calibration, $manager, 'Drift of 12 C', true);
    $followUp = $failed->followUpMaintenanceRecord;

    expect($failed->result)->toBe(CalibrationResult::Failed)
        ->and($failed->failure_reason)->toBe('Drift of 12 C')
        ->and($failed->next_calibration_due_on)->toBeNull()
        ->and($followUp)->not->toBeNull()
        ->and($followUp->maintenance_kind)->toBe(MaintenanceKind::Corrective)
        ->and($followUp->serialized_inventory_unit_id)->toBe($record->serialized_inventory_unit_id)
        ->and($followUp->description)->toContain('Drift of 12 C')
        ->and(Activity::query()->where('description', 'support.calibration.failed')->count())->toBe(1);

    [, , $second] = CalibrationFixtures::scenario();
    $withoutFollowUp = $service->fail(CalibrationFixtures::started($second, $manager), $manager, 'Out of range');

    expect($withoutFollowUp->follow_up_maintenance_record_id)->toBeNull();
});

it('only raises a follow-up repair for users who may create maintenance requests', function (): void {
    $agent = InstallationFixtures::agent();
    [, , $record] = CalibrationFixtures::scenario();
    $calibration = CalibrationFixtures::started($record, InstallationFixtures::manager());

    expect(fn () => app(EquipmentCalibrationService::class)->fail($calibration, $agent, 'Drift', true))->toThrow(ValidationException::class, 'follow-up repair')
        ->and($calibration->fresh()->isFinished())->toBeFalse();

    expect(app(EquipmentCalibrationService::class)->fail($calibration, $agent, 'Drift')->result)->toBe(CalibrationResult::Failed);
});

it('stores a certificate only for a passed calibration and keeps the replaced number in the audit trail', function (): void {
    $manager = InstallationFixtures::manager();
    $service = app(EquipmentCalibrationService::class);

    [, , $open] = CalibrationFixtures::scenario();
    $started = CalibrationFixtures::started($open, $manager);
    expect(fn () => $service->issueCertificate($started, $manager, 'CAL-1'))->toThrow(ValidationException::class, 'passed calibration');

    [, , $failedRecord] = CalibrationFixtures::scenario();
    $failed = $service->fail(CalibrationFixtures::started($failedRecord, $manager), $manager, 'Bad');
    expect(fn () => $service->issueCertificate($failed, $manager, 'CAL-2'))->toThrow(ValidationException::class, 'passed calibration');

    [, , $record] = CalibrationFixtures::scenario();
    $calibration = CalibrationFixtures::calibration($record, $manager, 2);

    expect(fn () => $service->issueCertificate($calibration, $manager, ' '))->toThrow(ValidationException::class, 'certificate number is required')
        ->and(fn () => $service->issueCertificate($calibration, $manager, 'CAL-3', now()->subDay()))->toThrow(ValidationException::class, 'expiry must be after');

    $issued = $service->issueCertificate($calibration, $manager, 'CAL-2026-77', now()->addYear());

    expect($issued->certificate_number)->toBe('CAL-2026-77')
        ->and($issued->hasCertificate())->toBeTrue()
        ->and($issued->certificate_expires_on->toDateString())->toBe(now()->addYear()->toDateString())
        ->and($issued->certificate_issued_at)->not->toBeNull();

    $reissued = $service->issueCertificate($issued, $manager, 'CAL-2026-78');

    expect($reissued->certificate_expires_on)->toBeNull();

    $audit = Activity::query()->where('description', 'support.calibration.certificate_issued')->latest('id')->first();

    expect($audit->properties['replaced_certificate_number'])->toBe('CAL-2026-77');
});

it('treats a closed or cancelled maintenance request as immutable for every calibration change', function (): void {
    $manager = InstallationFixtures::manager();
    $service = app(EquipmentCalibrationService::class);

    [, , $record] = CalibrationFixtures::scenario();
    $finished = CalibrationFixtures::calibration($record, $manager, 2);
    [, , $openRecord] = CalibrationFixtures::scenario();
    $open = CalibrationFixtures::started($openRecord, $manager);

    $record->update(['status' => MaintenanceStatus::Closed]);
    $openRecord->update(['status' => MaintenanceStatus::Cancelled]);

    expect(fn () => $service->issueCertificate($finished, $manager, 'CAL-9'))->toThrow(ValidationException::class, 'closed or cancelled')
        ->and(fn () => $service->recordMeasurement($open, 'furnace_temperature', '945', $manager))->toThrow(ValidationException::class, 'closed or cancelled')
        ->and(fn () => $service->complete($open, $manager))->toThrow(ValidationException::class, 'closed or cancelled')
        ->and(fn () => $service->fail($open, $manager, 'x'))->toThrow(ValidationException::class, 'closed or cancelled');
});

it('enforces the calibration permissions per role', function (): void {
    $manager = InstallationFixtures::manager();
    $agent = InstallationFixtures::agent();
    $reviewer = InstallationFixtures::reviewer();
    $service = app(EquipmentCalibrationService::class);

    [, , $record] = CalibrationFixtures::scenario();

    foreach ([$agent, $reviewer, User::factory()->customer()->create()] as $user) {
        expect(fn () => $service->start($record, $user, ['measurements' => CalibrationFixtures::measurements()]))->toThrow(AuthorizationException::class);
    }

    $calibration = CalibrationFixtures::started($record, $manager);

    // Agents may record measurements and complete; reviewers may only view.
    expect($service->recordMeasurement($calibration, 'furnace_temperature', '945', $agent)->result)->toBe(CalibrationMeasurementResult::Passed)
        ->and(fn () => $service->recordMeasurement($calibration, 'furnace_temperature', '945', $reviewer))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->complete($calibration, $reviewer))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->fail($calibration, $reviewer, 'x'))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->issueCertificate($calibration, $reviewer, 'CAL-1'))->toThrow(AuthorizationException::class)
        ->and($reviewer->can('view', $calibration))->toBeTrue()
        ->and($agent->can('viewAny', EquipmentCalibration::class))->toBeTrue()
        ->and($agent->can('create', EquipmentCalibration::class))->toBeFalse()
        ->and($agent->can('complete', $calibration))->toBeTrue();

    $service->complete($calibration, $agent);

    expect($calibration->fresh()->performed_by_employee_id)->toBeNull();
});

it('blocks every calibration ability while the feature flag is off but keeps the data', function (): void {
    $manager = InstallationFixtures::manager();
    [, , $record] = CalibrationFixtures::scenario();
    $calibration = CalibrationFixtures::calibration($record, $manager, 3);

    config(['support.calibration_enabled' => false]);

    foreach (['viewAny', 'view', 'update', 'complete'] as $ability) {
        expect($manager->can($ability, $calibration))->toBeFalse();
    }

    expect($manager->can('create', EquipmentCalibration::class))->toBeFalse()
        ->and(fn () => app(EquipmentCalibrationService::class)->recordMeasurement($calibration, 'furnace_temperature', '945', $manager))->toThrow(AuthorizationException::class)
        ->and(EquipmentCalibration::query()->count())->toBe(1)
        ->and($calibration->fresh()->certificate_number)->toBe('CAL-0001');
});

it('dispatches milestone events after the commit for completed and failed calibrations only', function (): void {
    Event::fake([EquipmentCalibrationMilestone::class]);
    $manager = InstallationFixtures::manager();

    [, , $passed] = CalibrationFixtures::scenario();
    CalibrationFixtures::calibration($passed, $manager, 2);
    [, , $failed] = CalibrationFixtures::scenario();
    app(EquipmentCalibrationService::class)->fail(CalibrationFixtures::started($failed, $manager), $manager, 'Drift');

    Event::assertDispatched(EquipmentCalibrationMilestone::class, fn (EquipmentCalibrationMilestone $event): bool => $event->record->is($passed) && $event->key->value === 'calibration.completed');
    Event::assertDispatched(EquipmentCalibrationMilestone::class, fn (EquipmentCalibrationMilestone $event): bool => $event->record->is($failed) && $event->key->value === 'calibration.failed');
    Event::assertDispatchedTimes(EquipmentCalibrationMilestone::class, 2);
});
