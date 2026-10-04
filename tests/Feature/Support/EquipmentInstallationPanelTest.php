<?php

declare(strict_types=1);

use App\Enums\CustomerAcceptanceStatus;
use App\Enums\MaintenanceStatus;
use App\Filament\Resources\MaintenanceRequests\Pages\ViewMaintenanceRequest;
use App\Filament\Resources\MaintenanceRequests\RelationManagers\InstallationRelationManager;
use App\Models\CustomerProfile;
use App\Models\EquipmentInstallation;
use App\Models\MaintenanceRecord;
use App\Models\User;
use App\Services\Support\EquipmentInstallationService;
use Database\Seeders\SupportPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\InstallationFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
    Storage::fake('local');
});

function panelFor(User $user, MaintenanceRecord $record)
{
    return Livewire::actingAs($user)->test(InstallationRelationManager::class, [
        'ownerRecord' => $record,
        'pageClass' => ViewMaintenanceRequest::class,
    ]);
}

it('preselects a single eligible shipment and only accepts eligible shipments in the picker', function (): void {
    [$customer, $unit, $record] = InstallationFixtures::scenario();
    $good = InstallationFixtures::shipment($customer, $unit);
    $planned = InstallationFixtures::shipment($customer, $unit, arrived: false);
    $foreign = InstallationFixtures::shipment(CustomerProfile::factory()->create(), $unit);
    $manager = InstallationFixtures::manager();

    panelFor($manager, $record)
        ->mountAction(TestAction::make('startInstallation')->table())
        ->assertSchemaStateSet(['shipment_id' => $good->id]);

    foreach ([$planned, $foreign] as $hidden) {
        panelFor($manager, $record)
            ->callAction(TestAction::make('startInstallation')->table(), ['shipment_id' => $hidden->id])
            ->assertHasFormErrors(['shipment_id']);
    }

    expect(EquipmentInstallation::query()->count())->toBe(0);

    panelFor($manager, $record)
        ->callAction(TestAction::make('startInstallation')->table(), ['shipment_id' => $good->id])
        ->assertHasNoFormErrors();

    expect(EquipmentInstallation::query()->sole()->shipment_id)->toBe($good->id);
});

it('requires a shipment when one is available and creates the installation from the selected one', function (): void {
    [$customer, $unit, $record] = InstallationFixtures::scenario();
    $good = InstallationFixtures::shipment($customer, $unit);
    $second = InstallationFixtures::shipment($customer, $unit);

    $manager = InstallationFixtures::manager();

    panelFor($manager, $record)
        ->callAction(TestAction::make('startInstallation')->table(), ['shipment_id' => null])
        ->assertHasFormErrors(['shipment_id' => 'required']);

    panelFor($manager, $record)->callAction(TestAction::make('startInstallation')->table(), ['shipment_id' => $second->id, 'installation_location' => 'Lab'])
        ->assertHasNoFormErrors();

    expect(EquipmentInstallation::query()->sole()->shipment_id)->toBe($second->id)
        ->and($good->id)->not->toBe($second->id);
});

it('starts without a shipment when none is available', function (): void {
    [, , $record] = InstallationFixtures::scenario();

    panelFor(InstallationFixtures::manager(), $record)
        ->callAction(TestAction::make('startInstallation')->table(), [])
        ->assertHasNoFormErrors();

    expect(EquipmentInstallation::query()->sole()->shipment_id)->toBeNull();
});

it('uploads evidence into the matching collection from each evidence action', function (): void {
    [, , $record] = InstallationFixtures::scenario();
    $manager = InstallationFixtures::manager();
    $installation = InstallationFixtures::installation($record, $manager);
    $component = panelFor($manager, $record);

    foreach ([
        'uploadInstallationEvidence' => EquipmentInstallation::MEDIA_PHOTOS,
        'uploadCommissioningEvidence' => EquipmentInstallation::MEDIA_COMMISSIONING,
        'uploadAcceptanceEvidence' => EquipmentInstallation::MEDIA_ACCEPTANCE,
    ] as $action => $collection) {
        $component->callTableAction($action, $installation, ['files' => [UploadedFile::fake()->image($collection.'.jpg')]]);

        expect($installation->fresh()->getMedia($collection))->toHaveCount(1);
    }

    expect($installation->fresh()->media)->toHaveCount(3);
});

it('shows uploaded evidence in the evidence viewer after a reload', function (): void {
    [, , $record] = InstallationFixtures::scenario();
    $manager = InstallationFixtures::manager();
    $installation = InstallationFixtures::installation($record, $manager);

    panelFor($manager, $record)->callTableAction('uploadInstallationEvidence', $installation, [
        'files' => [UploadedFile::fake()->image('rack-photo.jpg')],
    ]);

    panelFor($manager, $record)
        ->mountTableAction('viewEvidence', $installation)
        ->assertMountedActionModalSee('Installation photos')
        ->assertMountedActionModalSee('Preview')
        ->assertMountedActionModalSeeHtml('/media/');
});

it('does not expose upload or lifecycle actions to read-only reviewers and respects agent permissions', function (): void {
    [, , $record] = InstallationFixtures::scenario();
    $installation = InstallationFixtures::installation($record, InstallationFixtures::manager());

    $reviewer = panelFor(InstallationFixtures::reviewer(), $record);
    foreach (['uploadInstallationEvidence', 'completeInstallation', 'recordChecks'] as $action) {
        $reviewer->assertTableActionHidden($action, $installation);
    }
    $reviewer->assertTableActionVisible('viewEvidence', $installation);

    $agent = panelFor(InstallationFixtures::agent(), $record);
    $agent->assertTableActionVisible('uploadInstallationEvidence', $installation)
        ->assertTableActionVisible('recordChecks', $installation)
        ->assertActionHidden(TestAction::make('startInstallation')->table());
});

it('shows the workflow header with current step, next action and blocker', function (): void {
    [, , $record] = InstallationFixtures::scenario();
    $manager = InstallationFixtures::manager();

    panelFor($manager, $record)
        ->assertSee('Installation not started')
        ->assertSee('Start the installation.');

    $installation = InstallationFixtures::installation($record, $manager, 2);

    panelFor($manager, $record)
        ->assertSee('Installation completed')
        ->assertSee('Checklist incomplete.')
        ->assertSeeHtml('data-step="commissioning"');

    app(EquipmentInstallationService::class)->failCommissioning($installation, $manager, 'Drift in zone 2');

    panelFor($manager, $record)->assertSee('Commissioning failed')->assertSee('Drift in zone 2');
});

it('keeps the customer rejection reason visible in the panel after rejection', function (): void {
    [, , $record] = InstallationFixtures::scenario();
    $manager = InstallationFixtures::manager();
    $installation = InstallationFixtures::installation($record, $manager, 3);

    app(EquipmentInstallationService::class)->rejectByCustomer($installation, 'Exhaust noise too loud', $manager);

    expect($installation->fresh()->customer_acceptance_status)->toBe(CustomerAcceptanceStatus::Rejected);

    panelFor($manager, $record)
        ->assertSee('Exhaust noise too loud')
        ->assertSee('Rejected')
        ->assertTableActionHidden('customerAccepts', $installation);
});

it('hides check recording once the maintenance request is closed but keeps evidence available', function (): void {
    [, , $record] = InstallationFixtures::scenario();
    $manager = InstallationFixtures::manager();
    $installation = InstallationFixtures::installation($record, $manager);
    $record->update(['status' => MaintenanceStatus::Closed]);

    panelFor($manager, $record->fresh())
        ->assertTableActionHidden('recordChecks', $installation)
        ->assertTableActionVisible('viewEvidence', $installation);
});
