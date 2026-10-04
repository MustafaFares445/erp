<?php

declare(strict_types=1);

use App\Enums\MaintenanceKind;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Filament\Resources\ServiceAppointments\Pages\ListServiceAppointments;
use App\Models\EmployeeProfile;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\ServiceAppointment;
use App\Services\Support\ServiceAppointmentService;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\InstallationFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
});

/** @return array{0: ServiceAppointment, 1: MaintenanceRecord} */
function installationAppointment(MaintenanceKind $kind = MaintenanceKind::Installation): array
{
    [, , $record] = InstallationFixtures::scenario();
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

it('derives the visit purpose from the maintenance request without a purpose column', function (): void {
    [$installation] = installationAppointment();
    [$corrective] = installationAppointment(MaintenanceKind::Corrective);

    expect($installation->purpose())->toBe(MaintenanceKind::Installation)
        ->and($installation->isInstallation())->toBeTrue()
        ->and($corrective->purpose())->toBe(MaintenanceKind::Corrective)
        ->and($corrective->isInstallation())->toBeFalse()
        ->and(Schema::hasColumn('service_appointments', 'purpose'))->toBeFalse();
});

it('labels installation visits on the dispatch board with their checklist progress', function (): void {
    [$appointment, $record] = installationAppointment();
    $manager = InstallationFixtures::manager();

    Livewire::actingAs($manager)->test(ListServiceAppointments::class)
        ->assertSee('Installation')
        ->assertSee('Installation not started');

    InstallationFixtures::installation($record, $manager, 2);

    Livewire::actingAs($manager)->test(ListServiceAppointments::class)
        ->assertSee('0 / 6 checks');
});

it('labels other visit kinds without installation context', function (): void {
    [$appointment] = installationAppointment(MaintenanceKind::Corrective);
    $manager = InstallationFixtures::manager();

    Livewire::actingAs($manager)->test(ListServiceAppointments::class)
        ->assertSee('Corrective')
        ->assertDontSee('Installation not started')
        ->assertTableActionHidden('installationContext', $appointment);
});

it('opens the installation context for an installation visit and links to the same workspace', function (): void {
    [$appointment, $record] = installationAppointment();
    $manager = InstallationFixtures::manager();
    $installation = InstallationFixtures::installation($record, $manager, 3);

    Livewire::actingAs($manager)->test(ListServiceAppointments::class)
        ->assertTableActionVisible('installationContext', $appointment)
        ->mountTableAction('installationContext', $appointment)
        ->assertMountedActionModalSee('Awaiting customer acceptance')
        ->assertMountedActionModalSee($record->customer->company_name)
        ->assertMountedActionModalSee($record->serializedInventoryUnit->serial_number)
        ->assertMountedActionModalSeeHtml(MaintenanceRequestResource::getUrl('view', ['record' => $record]));

    expect($appointment->serviceRecord->maintenanceRecord->installation->is($installation))->toBeTrue();
});

it('hides the installation context when the feature is disabled or the user cannot view installations', function (): void {
    [$appointment] = installationAppointment();

    config(['support.equipment_installation_enabled' => false]);

    Livewire::actingAs(InstallationFixtures::manager())->test(ListServiceAppointments::class)
        ->assertTableActionHidden('installationContext', $appointment);

    config(['support.equipment_installation_enabled' => true]);

    Livewire::actingAs(InstallationFixtures::reviewer())->test(ListServiceAppointments::class)
        ->assertTableActionVisible('installationContext', $appointment);
});
