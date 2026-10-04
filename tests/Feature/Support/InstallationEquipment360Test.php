<?php

declare(strict_types=1);

use App\Enums\SerializedCustodyType;
use App\Enums\WarrantyStartTrigger;
use App\Filament\Resources\SupportEquipment\Pages\ViewSupportEquipment;
use App\Models\CustomerProfile;
use App\Models\EquipmentInstallation;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Services\Support\EquipmentInstallationEvidenceService;
use App\Services\Support\EquipmentInstallationService;
use Database\Seeders\SupportPermissionSeeder;
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

function equipment360(User $user, SerializedInventoryUnit $unit)
{
    return Livewire::actingAs($user)->test(ViewSupportEquipment::class, ['record' => $unit->getRouteKey()]);
}

it('shows an empty state for equipment with no installation', function (): void {
    $customer = CustomerProfile::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $customer->getKey(),
    ]);

    equipment360(InstallationFixtures::manager(), $unit)
        ->assertSee('Installation & Commissioning')
        ->assertSee('No installation recorded for this equipment.')
        ->assertDontSeeHtml('data-testid="installation-progress"');
});

it('shows a requested installation that has not started with its next action', function (): void {
    [, $unit, $record] = InstallationFixtures::scenario();

    equipment360(InstallationFixtures::manager(), $unit)
        ->assertSee('Installation not started')
        ->assertSee('Start the installation.')
        ->assertSee('Open installation request #'.$record->id);
});

it('shows a pending installation with shipment, location and next action', function (): void {
    [$customer, $unit, $record] = InstallationFixtures::scenario();
    $shipment = InstallationFixtures::shipment($customer, $unit);
    app(EquipmentInstallationService::class)->createForDeliveredEquipment($record, InstallationFixtures::manager(), [
        'shipment_id' => $shipment->id,
    ]);

    equipment360(InstallationFixtures::manager(), $unit)
        ->assertSee('Pending installation')
        ->assertSee('Complete the installation on site.')
        ->assertSee($shipment->tracking_number);
});

it('shows a completed installation with the technician and date', function (): void {
    [, $unit, $record] = InstallationFixtures::scenario();
    $installation = InstallationFixtures::installation($record, InstallationFixtures::manager(), 2);

    equipment360(InstallationFixtures::manager(), $unit)
        ->assertSee('Installation completed')
        ->assertSee($installation->installed_at->toDayDateTimeString());
});

it('shows a failed commissioning clearly with its reason', function (): void {
    [, $unit, $record] = InstallationFixtures::scenario();
    $actor = InstallationFixtures::manager();
    $installation = InstallationFixtures::installation($record, $actor, 2);
    app(EquipmentInstallationService::class)->failCommissioning($installation, $actor, 'Over-temperature in zone 3');

    equipment360(InstallationFixtures::manager(), $unit)
        ->assertSee('Commissioning failed')
        ->assertSee('Over-temperature in zone 3')
        ->assertSee('Correct the fault, update the checks and retry commissioning.');
});

it('shows passed commissioning awaiting customer acceptance', function (): void {
    [, $unit, $record] = InstallationFixtures::scenario();
    InstallationFixtures::installation($record, InstallationFixtures::manager(), 3);

    equipment360(InstallationFixtures::manager(), $unit)
        ->assertSee('Awaiting customer acceptance')
        ->assertSee('Record the customer acceptance or rejection.');
});

it('shows customer acceptance, the signatory and the warranty activation result', function (): void {
    [$customer, $unit, $record] = InstallationFixtures::scenario();
    InstallationFixtures::pendingEntitlement($customer, $unit, WarrantyStartTrigger::Commissioning);
    InstallationFixtures::installation($record, InstallationFixtures::manager(), 4);

    equipment360(InstallationFixtures::manager(), $unit)
        ->assertSee('Accepted')
        ->assertSee('Dr. Salem')
        ->assertSee('Active until '.today()->addMonthsNoOverflow(12)->toDateString());
});

it('lists evidence links only for users who may view the installation and never mutates custody', function (): void {
    [, $unit, $record] = InstallationFixtures::scenario();
    $manager = InstallationFixtures::manager();
    $installation = InstallationFixtures::installation($record, $manager, 2);
    app(EquipmentInstallationEvidenceService::class)->attach(
        $installation,
        EquipmentInstallation::MEDIA_PHOTOS,
        [UploadedFile::fake()->image('rack.jpg')->storeAs('installation-evidence', 'rack.jpg', 'local')],
        $manager,
    );
    $custodyBefore = $unit->fresh()->only(['custody_type', 'custody_reference_id', 'status']);

    equipment360($manager, $unit)
        ->assertSeeHtml('data-testid="equipment-installation-evidence"')
        ->assertSeeHtml('/media/');

    config(['support.equipment_installation_enabled' => false]);

    equipment360($manager, $unit)
        ->assertSee('Installation completed')
        ->assertDontSeeHtml('data-testid="equipment-installation-evidence"');

    expect($unit->fresh()->only(['custody_type', 'custody_reference_id', 'status']))->toBe($custodyBefore);
});
