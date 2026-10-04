<?php

declare(strict_types=1);

use App\Enums\CalibrationResult;
use App\Enums\MaintenanceIntervalType;
use App\Enums\MaintenanceKind;
use App\Enums\SerializedCustodyType;
use App\Enums\SupportPermission;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Filament\Resources\ServiceAppointments\Pages\ListServiceAppointments;
use App\Filament\Resources\SupportEquipment\Pages\ViewSupportEquipment;
use App\Filament\Widgets\SupportCalibrationQueue;
use App\Models\CustomerProfile;
use App\Models\EmployeeProfile;
use App\Models\EquipmentCalibration;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceSchedule;
use App\Models\MaintenanceTask;
use App\Models\SerializedInventoryUnit;
use App\Models\ServiceAppointment;
use App\Models\User;
use App\Services\Support\CalibrationProgressResolver;
use App\Services\Support\EquipmentCalibrationEvidenceService;
use App\Services\Support\EquipmentCalibrationService;
use App\Services\Support\ServiceAppointmentService;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\CalibrationFixtures;
use Tests\Support\InstallationFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
    Storage::fake('local');
});

function calibrationProgress(MaintenanceRecord $record): array
{
    return app(CalibrationProgressResolver::class)->resolve($record->fresh());
}

/** @return array<string, string> step key => state */
function calibrationStepStates(array $progress): array
{
    return collect($progress['steps'])->mapWithKeys(fn (array $step): array => [$step['key'] => $step['state']])->all();
}

it('describes a calibration that has not started and flags missing equipment', function (): void {
    [, , $record] = CalibrationFixtures::scenario();

    $progress = calibrationProgress($record);

    expect($progress['status'])->toBe('Calibration not started')
        ->and($progress['next_action'])->toBe('Start the calibration.')
        ->and($progress['blocker'])->toBeNull()
        ->and(calibrationStepStates($progress)['equipment'])->toBe('current');

    $record->update(['serialized_inventory_unit_id' => null]);

    expect(calibrationProgress($record)['blocker'])->toBe('Link serialized equipment to this request first.')
        ->and(app(CalibrationProgressResolver::class)->previous($record->fresh()))->toBeNull();
});

it('walks the workflow from measuring to certified and shows the blocker at each step', function (): void {
    $manager = InstallationFixtures::manager();
    [, , $record] = CalibrationFixtures::scenario();
    $calibration = CalibrationFixtures::started($record, $manager);
    $service = app(EquipmentCalibrationService::class);

    $progress = calibrationProgress($record);
    expect($progress['status'])->toBe('Measuring')
        ->and($progress['blocker'])->toBe('Measurements incomplete.')
        ->and($progress['measured'])->toBe(0)
        ->and($progress['total'])->toBe(2)
        ->and(calibrationStepStates($progress)['measurements'])->toBe('current');

    $service->recordMeasurement($calibration, 'furnace_temperature', '999', $manager);
    $progress = calibrationProgress($record);
    expect($progress['status'])->toBe('Out of tolerance')
        ->and($progress['failed'])->toBeTrue()
        ->and($progress['blocker'])->toBe('A mandatory measurement is out of tolerance.')
        ->and(calibrationStepStates($progress)['measurements'])->toBe('failed');

    $service->recordMeasurement($calibration, 'furnace_temperature', '945', $manager);
    $progress = calibrationProgress($record);
    expect($progress['status'])->toBe('Ready to complete')
        ->and($progress['blocker'])->toBeNull()
        ->and($progress['measured'])->toBe(1)
        ->and(calibrationStepStates($progress)['result'])->toBe('current');

    $service->complete($calibration, $manager, CalibrationResult::PassedWithAdjustment, null, now()->addMonths(6));
    $progress = calibrationProgress($record);
    expect($progress['status'])->toBe('Passed with adjustment')
        ->and($progress['color'])->toBe('warning')
        ->and($progress['next_action'])->toBe('Issue the calibration certificate.')
        ->and(calibrationStepStates($progress))->toMatchArray(['result' => 'done', 'certificate' => 'current', 'next_due' => 'done']);

    $service->issueCertificate($calibration->refresh(), $manager, 'CAL-77');
    $progress = calibrationProgress($record);
    expect($progress['next_action'])->toBe('Calibration is complete.')
        ->and(calibrationStepStates($progress)['certificate'])->toBe('done')
        ->and(collect($progress['steps'])->firstWhere('key', 'certificate')['detail'])->toBe('CAL-77');
});

it('shows a failed calibration as failed with its follow-up guidance', function (): void {
    $manager = InstallationFixtures::manager();
    [, , $record] = CalibrationFixtures::scenario();
    app(EquipmentCalibrationService::class)->fail(CalibrationFixtures::started($record, $manager), $manager, 'Drift');

    $progress = calibrationProgress($record);

    expect($progress['status'])->toBe('Calibration failed')
        ->and($progress['color'])->toBe('danger')
        ->and($progress['failed'])->toBeTrue()
        ->and($progress['blocker'])->toBe('Calibration failed.')
        ->and($progress['next_action'])->toContain('follow up')
        ->and(calibrationStepStates($progress))->toMatchArray(['result' => 'failed', 'certificate' => 'upcoming']);
});

it('keeps a calibration without any measurements in the measuring state', function (): void {
    $manager = InstallationFixtures::manager();
    [, , $record] = CalibrationFixtures::scenario();
    $calibration = CalibrationFixtures::started($record, $manager);
    $calibration->measurements()->delete();

    expect(calibrationProgress($record)['status'])->toBe('Measuring');
});

it('reports the previous finished calibration of the same equipment only', function (): void {
    $manager = InstallationFixtures::manager();
    [$customer, $unit, $first] = CalibrationFixtures::scenario();
    $previous = CalibrationFixtures::calibration($first, $manager, 3);
    $current = MaintenanceRecord::factory()->create([
        'customer_id' => $customer->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
        'maintenance_kind' => MaintenanceKind::Calibration,
    ]);

    $resolver = app(CalibrationProgressResolver::class);

    expect($resolver->previous($current))->toMatchArray([
        'result' => 'Passed',
        'certificate' => 'CAL-0001',
        'next_due' => $previous->next_calibration_due_on->toFormattedDateString(),
    ])
        ->and($resolver->previous($first))->toBeNull();

    // An unfinished calibration is never "previous".
    CalibrationFixtures::started($current, $manager);
    [, , $third] = CalibrationFixtures::scenario();
    $third->update(['serialized_inventory_unit_id' => $unit->getKey()]);

    expect($resolver->previous($third)['certificate'])->toBe('CAL-0001');
});

function equipmentView(User $user, SerializedInventoryUnit $unit)
{
    return Livewire::actingAs($user)->test(ViewSupportEquipment::class, ['record' => $unit->getRouteKey()]);
}

it('shows an empty calibration state and the schedule-derived next due dates on Equipment 360', function (): void {
    $customer = CustomerProfile::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $customer->getKey(),
    ]);
    $manager = InstallationFixtures::manager();

    equipmentView($manager, $unit)
        ->assertSee('No calibration recorded for this equipment.')
        ->assertSee('Next preventive maintenance')
        ->assertSee('Next inspection')
        ->assertSee('Next calibration')
        ->assertDontSeeHtml('data-testid="equipment-last-calibration"');

    foreach ([[MaintenanceKind::Preventive, 10], [MaintenanceKind::Inspection, 20], [MaintenanceKind::Calibration, 30]] as [$kind, $days]) {
        MaintenanceSchedule::factory()->create([
            'serialized_inventory_unit_id' => $unit->getKey(),
            'customer_id' => $customer->getKey(),
            'maintenance_kind' => $kind,
            'next_due_on' => now()->addDays($days)->toDateString(),
            'created_by' => $manager->getKey(),
        ]);
    }

    // The earliest active schedule per kind wins; an inactive one never shows.
    MaintenanceSchedule::factory()->inactive()->create([
        'serialized_inventory_unit_id' => $unit->getKey(),
        'customer_id' => $customer->getKey(),
        'maintenance_kind' => MaintenanceKind::Calibration,
        'next_due_on' => now()->addDay()->toDateString(),
        'created_by' => $manager->getKey(),
    ]);

    equipmentView($manager, $unit)
        ->assertSeeHtml('data-testid="equipment-next-due"')
        ->assertSee(now()->addDays(10)->toFormattedDateString())
        ->assertSee(now()->addDays(20)->toFormattedDateString())
        ->assertSee(now()->addDays(30)->toFormattedDateString())
        ->assertDontSee(now()->addDay()->toFormattedDateString());
});

it('shows the last calibration with result, certificate, next due, measurements and evidence on Equipment 360', function (): void {
    $manager = InstallationFixtures::manager();
    [, $unit, $record] = CalibrationFixtures::scenario();
    $calibration = CalibrationFixtures::calibration($record, $manager, 3);
    $path = UploadedFile::fake()->image('cert.jpg')->storeAs('calibration-evidence', 'cert.jpg', 'local');
    app(EquipmentCalibrationEvidenceService::class)->attach($calibration, EquipmentCalibration::MEDIA_CERTIFICATES, [$path], $manager);
    $calibration->update(['performed_by_employee_id' => EmployeeProfile::factory()->create()->getKey()]);

    equipmentView($manager, $unit)
        ->assertSeeHtml('data-testid="equipment-last-calibration"')
        ->assertSee('CAL-0001')
        ->assertSee('Passed')
        ->assertSee($calibration->next_calibration_due_on->toFormattedDateString())
        ->assertSee('Furnace temperature')
        ->assertSeeHtml('data-testid="equipment-calibration-evidence"')
        ->assertSeeHtml('/admin/equipment-calibrations/'.$calibration->id.'/media/');
});

it('shows an in-progress calibration with a link to its request and the failure reason of a failed one', function (): void {
    $manager = InstallationFixtures::manager();
    [, $unit, $record] = CalibrationFixtures::scenario();
    $calibration = CalibrationFixtures::started($record, $manager);

    equipmentView($manager, $unit)
        ->assertSeeHtml('data-testid="equipment-open-calibration"')
        ->assertSee('Open calibration request #'.$record->id)
        ->assertSeeHtml(MaintenanceRequestResource::getUrl('view', ['record' => $record]))
        ->assertDontSeeHtml('data-testid="equipment-last-calibration"');

    app(EquipmentCalibrationService::class)->fail($calibration, $manager, 'Drift of 12 C');

    equipmentView($manager, $unit)
        ->assertSee('Drift of 12 C')
        ->assertSee('Failed');
});

it('hides calibration evidence from users who cannot view calibrations', function (): void {
    $manager = InstallationFixtures::manager();
    [, $unit, $record] = CalibrationFixtures::scenario();
    $calibration = CalibrationFixtures::calibration($record, $manager, 3);
    $path = UploadedFile::fake()->image('cert.jpg')->storeAs('calibration-evidence', 'cert.jpg', 'local');
    app(EquipmentCalibrationEvidenceService::class)->attach($calibration, EquipmentCalibration::MEDIA_CERTIFICATES, [$path], $manager);

    $viewer = User::factory()->employee()->create();
    $viewer->givePermissionTo(SupportPermission::Equipment360View->value);

    equipmentView($viewer, $unit)
        ->assertSee('CAL-0001')
        ->assertDontSeeHtml('data-testid="equipment-calibration-evidence"');
});

it('hides the whole calibration section on Equipment 360 while calibration is disabled', function (): void {
    $manager = InstallationFixtures::manager();
    [, $unit, $record] = CalibrationFixtures::scenario();
    CalibrationFixtures::calibration($record, $manager, 3);

    config(['support.calibration_enabled' => false]);

    equipmentView($manager, $unit)
        ->assertDontSeeHtml('data-testid="equipment-calibration-section"')
        ->assertDontSee('CAL-0001');
});

/** @return array{0: ServiceAppointment, 1: MaintenanceRecord} */
function calibrationAppointment(MaintenanceKind $kind = MaintenanceKind::Calibration): array
{
    [, , $record] = CalibrationFixtures::scenario();
    $record->update(['maintenance_kind' => $kind]);
    $task = MaintenanceTask::factory()->create(['maintenance_record_id' => $record->getKey()]);
    $appointment = app(ServiceAppointmentService::class)->createScheduled(
        $task,
        EmployeeProfile::factory()->create(),
        now()->addDay()->startOfHour(),
        now()->addDay()->startOfHour()->addHours(2),
        null,
        InstallationFixtures::manager(),
    );

    return [$appointment->fresh(), $record];
}

it('labels calibration visits on the dispatch board and derives the purpose without a column', function (): void {
    [$appointment, $record] = calibrationAppointment();
    $manager = InstallationFixtures::manager();

    expect($appointment->purpose())->toBe(MaintenanceKind::Calibration)
        ->and($appointment->isCalibration())->toBeTrue()
        ->and($appointment->isInstallation())->toBeFalse();

    Livewire::actingAs($manager)->test(ListServiceAppointments::class)
        ->assertSee('Calibration')
        ->assertSee('Calibration not started');

    $calibration = CalibrationFixtures::started($record, $manager);
    CalibrationFixtures::measure($calibration, $manager);

    Livewire::actingAs($manager)->test(ListServiceAppointments::class)
        ->assertSee('1 / 2 measurements')
        ->assertSee('In progress');

    app(EquipmentCalibrationService::class)->complete($calibration, $manager);

    Livewire::actingAs($manager)->test(ListServiceAppointments::class)
        ->assertSee('1 / 2 measurements')
        ->assertSee('Passed');
});

it('does not give other visit kinds calibration context', function (): void {
    [$appointment] = calibrationAppointment(MaintenanceKind::Corrective);

    expect($appointment->isCalibration())->toBeFalse();

    Livewire::actingAs(InstallationFixtures::manager())->test(ListServiceAppointments::class)
        ->assertSee('Corrective')
        ->assertDontSee('Calibration not started')
        ->assertTableActionHidden('calibrationContext', $appointment);
});

it('opens the calibration context with measurements, result and certificate and links to the same workspace', function (): void {
    [$appointment, $record] = calibrationAppointment();
    $manager = InstallationFixtures::manager();
    CalibrationFixtures::calibration($record, $manager, 3);

    Livewire::actingAs($manager)->test(ListServiceAppointments::class)
        ->assertTableActionVisible('calibrationContext', $appointment)
        ->assertTableActionHidden('installationContext', $appointment)
        ->mountTableAction('calibrationContext', $appointment)
        ->assertMountedActionModalSee('Furnace temperature')
        ->assertMountedActionModalSee('943')
        ->assertMountedActionModalSee('CAL-0001')
        ->assertMountedActionModalSee($record->customer->company_name)
        ->assertMountedActionModalSeeHtml(MaintenanceRequestResource::getUrl('view', ['record' => $record]));
});

it('hides the calibration context when the feature is disabled', function (): void {
    [$appointment] = calibrationAppointment();

    config(['support.calibration_enabled' => false]);

    Livewire::actingAs(InstallationFixtures::manager())->test(ListServiceAppointments::class)
        ->assertTableActionHidden('calibrationContext', $appointment);
});

/** @return array{0: SerializedInventoryUnit, 1: CustomerProfile} */
function queueUnit(): array
{
    $customer = CustomerProfile::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $customer->getKey(),
    ]);

    return [$unit, $customer];
}

function queueSchedule(SerializedInventoryUnit $unit, int $dueInDays, bool $active = true, MaintenanceKind $kind = MaintenanceKind::Calibration): void
{
    MaintenanceSchedule::factory()->create([
        'serialized_inventory_unit_id' => $unit->getKey(),
        'maintenance_kind' => $kind,
        'interval_type' => MaintenanceIntervalType::Months,
        'next_due_on' => now()->addDays($dueInDays)->toDateString(),
        'is_active' => $active,
        'created_by' => InstallationFixtures::manager()->getKey(),
    ]);
}

it('lists equipment whose calibration is overdue, due soon or failed in the operational queue', function (): void {
    [$overdue] = queueUnit();
    [$soon] = queueUnit();
    [$far] = queueUnit();
    [$inactive] = queueUnit();
    [$preventiveOnly] = queueUnit();
    [$failedUnit] = queueUnit();
    [$superseded] = queueUnit();

    queueSchedule($overdue, -5);
    queueSchedule($soon, 10);
    queueSchedule($far, 90);
    queueSchedule($inactive, 5, active: false);
    queueSchedule($preventiveOnly, 5, kind: MaintenanceKind::Preventive);

    $failed = EquipmentCalibration::factory()->failed()->create(['serialized_inventory_unit_id' => $failedUnit->getKey()]);
    EquipmentCalibration::factory()->failed()->create(['serialized_inventory_unit_id' => $superseded->getKey()]);
    EquipmentCalibration::factory()->passed()->create([
        'serialized_inventory_unit_id' => $superseded->getKey(),
        'maintenance_record_id' => MaintenanceRecord::factory()->create(['maintenance_kind' => MaintenanceKind::Calibration])->getKey(),
    ]);

    $user = InstallationFixtures::manager();

    Livewire::actingAs($user)->test(SupportCalibrationQueue::class)
        ->assertSuccessful()
        ->assertSee('Calibration queue')
        ->assertCanSeeTableRecords([$overdue, $soon, $failedUnit])
        ->assertCanNotSeeTableRecords([$far, $inactive, $preventiveOnly, $superseded])
        ->assertSee('Overdue')
        ->assertSee('Due soon')
        ->assertSee('Failed calibration');

    expect($failed->serialized_inventory_unit_id)->toBe($failedUnit->id);
});

it('gates the calibration queue on the calibration view permission and the feature flag', function (): void {
    $user = User::factory()->admin()->create();
    $this->actingAs($user);

    expect(SupportCalibrationQueue::canView())->toBeFalse();

    $user->givePermissionTo(SupportPermission::CalibrationView->value);

    expect(SupportCalibrationQueue::canView())->toBeTrue();

    config(['support.calibration_enabled' => false]);

    expect(SupportCalibrationQueue::canView())->toBeFalse();
});
