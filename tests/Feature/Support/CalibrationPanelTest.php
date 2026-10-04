<?php

declare(strict_types=1);

use App\Enums\CalibrationResult;
use App\Enums\MaintenanceKind;
use App\Enums\MaintenanceStatus;
use App\Filament\Resources\MaintenanceRequests\Pages\ViewMaintenanceRequest;
use App\Filament\Resources\MaintenanceRequests\RelationManagers\CalibrationRelationManager;
use App\Filament\Resources\MaintenanceRequests\RelationManagers\InstallationRelationManager;
use App\Models\EquipmentCalibration;
use App\Models\MaintenanceRecord;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Support\EquipmentCalibrationService;
use Database\Seeders\SupportPermissionSeeder;
use Filament\Actions\Testing\TestAction;
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

function calibrationPanel(User $user, MaintenanceRecord $record)
{
    return Livewire::actingAs($user)->test(CalibrationRelationManager::class, [
        'ownerRecord' => $record,
        'pageClass' => ViewMaintenanceRequest::class,
    ]);
}

it('shows the calibration workspace only on calibration requests while the flag is on', function (): void {
    [, , $record] = CalibrationFixtures::scenario();

    expect(CalibrationRelationManager::canViewForRecord($record, ViewMaintenanceRequest::class))->toBeTrue()
        ->and(CalibrationRelationManager::getTitle($record, ViewMaintenanceRequest::class))->toBe('Calibration')
        ->and(InstallationRelationManager::canViewForRecord($record, ViewMaintenanceRequest::class))->toBeFalse();

    $record->update(['maintenance_kind' => MaintenanceKind::Corrective]);

    expect(CalibrationRelationManager::canViewForRecord($record, ViewMaintenanceRequest::class))->toBeFalse();

    $record->update(['maintenance_kind' => MaintenanceKind::Calibration]);
    config(['support.calibration_enabled' => false]);

    expect(CalibrationRelationManager::canViewForRecord($record, ViewMaintenanceRequest::class))->toBeFalse();
});

it('starts a calibration from the measurement template and lists the technician and provider', function (): void {
    [, , $record] = CalibrationFixtures::scenario();
    $manager = InstallationFixtures::manager();
    $provider = Supplier::factory()->create(['name' => 'Metrology Partner LLC', 'is_active' => true]);

    calibrationPanel($manager, $record)
        ->assertSee('Calibration not started')
        ->assertSee('Start the calibration.')
        ->callAction(TestAction::make('startCalibration')->table(), [
            'measurements' => [
                ['label' => 'Furnace temperature', 'expected_value' => 950, 'minimum_value' => 940, 'maximum_value' => 960, 'unit' => 'C', 'is_required' => true],
            ],
            'external_provider_id' => $provider->id,
            'standard_reference' => 'ISO 17025 thermocouple',
            'instrument_reference' => 'RT-204',
            'notes' => 'Annual calibration',
        ])
        ->assertHasNoFormErrors();

    $calibration = EquipmentCalibration::query()->sole();

    expect($calibration->external_provider_id)->toBe($provider->id)
        ->and($calibration->standard_reference)->toBe('ISO 17025 thermocouple')
        ->and($calibration->measurements)->toHaveCount(1);

    calibrationPanel($manager, $record)
        ->assertSee('Metrology Partner LLC')
        ->assertSee('Measuring')
        ->assertSeeHtml('data-testid="calibration-measurements"')
        ->assertActionHidden(TestAction::make('startCalibration')->table());
});

it('turns a domain failure at start into a notification instead of an error page', function (): void {
    [, , $record] = CalibrationFixtures::scenario();
    $record->update(['status' => MaintenanceStatus::Closed]);

    calibrationPanel(InstallationFixtures::manager(), $record)
        ->callAction(TestAction::make('startCalibration')->table(), [
            'measurements' => [['label' => 'Pressure', 'maximum_value' => 5, 'is_required' => true]],
        ])
        ->assertNotified('Unable to update the calibration');

    expect(EquipmentCalibration::query()->count())->toBe(0);
});

it('records measurements, completes the calibration and issues the certificate from the panel', function (): void {
    [, , $record] = CalibrationFixtures::scenario();
    $manager = InstallationFixtures::manager();
    $calibration = CalibrationFixtures::started($record, $manager);

    calibrationPanel($manager, $record)
        ->mountTableAction('recordMeasurements', $calibration)
        ->assertSchemaStateSet(fn (array $state): bool => count($state['measurements']) === 2
            && collect($state['measurements'])->pluck('limits')->first() === '940 – 960 C');

    calibrationPanel($manager, $record)
        ->callTableAction('recordMeasurements', $calibration, [
            'measurements' => [
                ['measurement_key' => 'furnace_temperature', 'label' => 'Furnace temperature', 'actual_value' => '943', 'notes' => 'ok'],
                ['measurement_key' => 'uniformity', 'label' => 'Temperature uniformity', 'actual_value' => null, 'notes' => null],
            ],
        ]);

    $calibration->refresh();

    expect($calibration->measurements->first()->actual_value)->toBe('943.0000')
        ->and($calibration->measurements->first()->notes)->toBe('ok')
        ->and($calibration->measurements->last()->isRecorded())->toBeFalse();

    calibrationPanel($manager, $record)
        ->assertSee('Ready to complete')
        ->callTableAction('completeCalibration', $calibration, ['result' => CalibrationResult::PassedWithAdjustment->value, 'next_calibration_due_on' => now()->addMonths(6)->toDateString()]);

    $calibration->refresh();

    expect($calibration->result)->toBe(CalibrationResult::PassedWithAdjustment)
        ->and($calibration->next_calibration_due_on->toDateString())->toBe(now()->addMonths(6)->toDateString());

    calibrationPanel($manager, $record)
        ->assertSee('Issue the calibration certificate.')
        ->assertTableActionHidden('completeCalibration', $calibration)
        ->assertTableActionHidden('failCalibration', $calibration)
        ->callTableAction('issueCertificate', $calibration, ['certificate_number' => 'CAL-2026-5', 'certificate_expires_on' => now()->addYear()->toDateString()]);

    expect($calibration->fresh()->certificate_number)->toBe('CAL-2026-5');

    calibrationPanel($manager, $record)
        ->assertSee('Calibration is complete.')
        ->assertSee('CAL-2026-5')
        ->assertTableActionHidden('issueCertificate', $calibration);
});

it('lets a technician with only complete permission measure and complete but not start', function (): void {
    [, , $record] = CalibrationFixtures::scenario();
    $calibration = CalibrationFixtures::started($record, InstallationFixtures::manager());
    $agent = calibrationPanel(InstallationFixtures::agent(), $record);

    $agent->assertActionHidden(TestAction::make('startCalibration')->table())
        ->assertTableActionVisible('recordMeasurements', $calibration)
        ->assertTableActionVisible('completeCalibration', $calibration)
        ->callTableAction('recordMeasurements', $calibration, ['measurements' => [
            ['measurement_key' => 'furnace_temperature', 'label' => 'x', 'actual_value' => '945', 'notes' => null],
        ]]);

    expect($calibration->fresh()->measurements->first()->isRecorded())->toBeTrue();
});

it('explains why completion is blocked and offers failing the calibration', function (): void {
    [, , $record] = CalibrationFixtures::scenario();
    $manager = InstallationFixtures::manager();
    $calibration = CalibrationFixtures::started($record, $manager);

    calibrationPanel($manager, $record)
        ->callTableAction('completeCalibration', $calibration, ['result' => CalibrationResult::Passed->value])
        ->assertNotified('Unable to update the calibration');

    expect($calibration->fresh()->isFinished())->toBeFalse();

    CalibrationFixtures::measure($calibration, $manager, '999');

    calibrationPanel($manager, $record)
        ->assertSee('Out of tolerance')
        ->assertSee('A mandatory measurement is out of tolerance.')
        ->callTableAction('failCalibration', $calibration, ['reason' => 'Drift of 49 C', 'raise_follow_up' => true]);

    $calibration->refresh();

    expect($calibration->result)->toBe(CalibrationResult::Failed)
        ->and($calibration->follow_up_maintenance_record_id)->not->toBeNull();

    calibrationPanel($manager, $record)
        ->assertSee('Calibration failed')
        ->assertSee('Drift of 49 C')
        ->assertTableActionHidden('recordMeasurements', $calibration)
        ->assertTableActionHidden('issueCertificate', $calibration);
});

it('offers the follow-up repair toggle only to users who may create maintenance requests', function (): void {
    [, , $record] = CalibrationFixtures::scenario();
    $calibration = CalibrationFixtures::started($record, InstallationFixtures::manager());

    calibrationPanel(InstallationFixtures::agent(), $record)
        ->mountTableAction('failCalibration', $calibration)
        ->assertFormFieldHidden('raise_follow_up');

    calibrationPanel(InstallationFixtures::manager(), $record)
        ->mountTableAction('failCalibration', $calibration)
        ->assertFormFieldVisible('raise_follow_up');
});

it('uploads certificates and evidence into the matching private collections and shows them after a reload', function (): void {
    [, , $record] = CalibrationFixtures::scenario();
    $manager = InstallationFixtures::manager();
    $calibration = CalibrationFixtures::started($record, $manager);

    foreach ([
        'uploadCertificate' => EquipmentCalibration::MEDIA_CERTIFICATES,
        'uploadCalibrationEvidence' => EquipmentCalibration::MEDIA_EVIDENCE,
    ] as $action => $collection) {
        calibrationPanel($manager, $record)->callTableAction($action, $calibration, ['files' => [UploadedFile::fake()->image($collection.'.jpg')]]);

        expect($calibration->fresh()->getMedia($collection))->toHaveCount(1);
    }

    calibrationPanel($manager, $record)
        ->mountTableAction('viewEvidence', $calibration)
        ->assertMountedActionModalSee('Calibration certificates')
        ->assertMountedActionModalSee('Calibration evidence')
        ->assertMountedActionModalSeeHtml('/media/');
});

it('keeps read-only reviewers out of the calibration actions and honours the closed request', function (): void {
    [, , $record] = CalibrationFixtures::scenario();
    $calibration = CalibrationFixtures::started($record, InstallationFixtures::manager());

    $reviewer = calibrationPanel(InstallationFixtures::reviewer(), $record);
    foreach (['recordMeasurements', 'completeCalibration', 'failCalibration', 'uploadCertificate', 'uploadCalibrationEvidence'] as $action) {
        $reviewer->assertTableActionHidden($action, $calibration);
    }
    $reviewer->assertTableActionVisible('viewEvidence', $calibration);

    $service = app(EquipmentCalibrationService::class);
    $service->fail($calibration, InstallationFixtures::manager(), 'Drift');

    $record->update(['status' => MaintenanceStatus::Closed]);

    calibrationPanel(InstallationFixtures::manager(), $record->fresh())
        ->callTableAction('uploadCalibrationEvidence', $calibration, ['files' => [UploadedFile::fake()->image('late.jpg')]])
        ->assertHasNoTableActionErrors();
});

it('shows the previous calibration of the same equipment above the current one', function (): void {
    [$customer, $unit, $first] = CalibrationFixtures::scenario();
    $manager = InstallationFixtures::manager();
    $previous = CalibrationFixtures::calibration($first, $manager, 3);

    $next = MaintenanceRecord::factory()->create([
        'customer_id' => $customer->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
        'maintenance_kind' => MaintenanceKind::Calibration,
    ]);

    calibrationPanel($manager, $next)
        ->assertSeeHtml('data-testid="calibration-previous"')
        ->assertSee('Previous calibration')
        ->assertSee('CAL-0001')
        ->assertSee($previous->next_calibration_due_on->toFormattedDateString());
});

it('renders the calibration workspace in Arabic with translated status, next action and labels', function (): void {
    [, , $record] = CalibrationFixtures::scenario();
    $manager = InstallationFixtures::manager();
    app()->setLocale('ar');

    calibrationPanel($manager, $record)
        ->assertSee('لم تبدأ المعايرة')
        ->assertSee('ابدأ المعايرة.')
        ->assertSee('بدء المعايرة');

    $calibration = CalibrationFixtures::started($record, $manager);
    CalibrationFixtures::measure($calibration, $manager);

    calibrationPanel($manager, $record)
        ->assertSee('القياسات')
        ->assertSee('الحدود')
        ->assertSee('جاهزة للإكمال')
        ->assertSee('أكمل المعايرة.');
});
