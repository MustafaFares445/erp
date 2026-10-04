<?php

declare(strict_types=1);

use App\Enums\CommissioningStatus;
use App\Enums\CustomerAcceptanceStatus;
use App\Enums\InstallationCheckResult;
use App\Enums\MaintenanceKind;
use App\Enums\SerializedCustodyType;
use App\Filament\Resources\MaintenanceRequests\Pages\ViewMaintenanceRequest;
use App\Filament\Resources\MaintenanceRequests\RelationManagers\InstallationRelationManager;
use App\Models\CustomerProfile;
use App\Models\EquipmentInstallation;
use App\Models\MaintenanceRecord;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use Database\Seeders\SupportPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
    $this->actingAs(tap(User::factory()->admin()->create())->assignRole('Support Manager'));
});

function installationRecord(): MaintenanceRecord
{
    $customer = CustomerProfile::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $customer->getKey(),
    ]);

    return MaintenanceRecord::factory()->create([
        'customer_id' => $customer->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
        'maintenance_kind' => MaintenanceKind::Installation,
    ]);
}

function installationManagerFor(MaintenanceRecord $record)
{
    return Livewire::test(InstallationRelationManager::class, [
        'ownerRecord' => $record,
        'pageClass' => ViewMaintenanceRequest::class,
    ]);
}

it('only shows the installation panel on installation maintenance requests', function (): void {
    $installation = installationRecord();
    $corrective = MaintenanceRecord::factory()->create(['maintenance_kind' => MaintenanceKind::Corrective]);

    expect(InstallationRelationManager::canViewForRecord($installation, ViewMaintenanceRequest::class))->toBeTrue()
        ->and(InstallationRelationManager::canViewForRecord($corrective, ViewMaintenanceRequest::class))->toBeFalse()
        ->and(InstallationRelationManager::getTitle($installation, ViewMaintenanceRequest::class))->toBe('Installation & Commissioning');

    config(['support.equipment_installation_enabled' => false]);

    expect(InstallationRelationManager::canViewForRecord($installation, ViewMaintenanceRequest::class))->toBeFalse();
});

it('drives the installation lifecycle from the relation manager actions', function (): void {
    $record = installationRecord();

    installationManagerFor($record)
        ->callAction(TestAction::make('startInstallation')->table(), ['installation_location' => 'Lab 1', 'notes' => 'Second floor'])
        ->assertHasNoErrors();

    $installation = EquipmentInstallation::query()->where('maintenance_record_id', $record->getKey())->sole();

    expect($installation->installation_location)->toBe('Lab 1')
        ->and($installation->checks)->not->toBeEmpty();

    $component = installationManagerFor($record);
    $component->assertCanSeeTableRecords([$installation])
        ->assertActionHidden(TestAction::make('startInstallation')->table());

    $component->callTableAction('recordChecks', $installation, [
        'checks' => $installation->checks->map(fn ($check): array => [
            'check_key' => $check->check_key,
            'label' => $check->label,
            'result' => InstallationCheckResult::Passed->value,
            'measured_value' => '1.5',
            'unit' => 'bar',
            'notes' => 'ok',
        ])->all(),
    ]);
    expect($installation->checks()->where('result', InstallationCheckResult::Passed->value)->count())->toBe($installation->checks->count());

    $component->callTableAction('completeInstallation', $installation);
    $component->callTableAction('passCommissioning', $installation);
    $component->callTableAction('customerAccepts', $installation, ['signatory' => 'Dr. Salem']);

    $installation->refresh();

    expect($installation->isInstalled())->toBeTrue()
        ->and($installation->commissioning_status)->toBe(CommissioningStatus::Passed)
        ->and($installation->customer_acceptance_status)->toBe(CustomerAcceptanceStatus::Accepted);
});

it('supports failing commissioning and customer rejection, surfacing rule violations as notifications', function (): void {
    $record = installationRecord();
    $component = installationManagerFor($record);
    $component->callAction(TestAction::make('startInstallation')->table(), []);

    $installation = EquipmentInstallation::query()->where('maintenance_record_id', $record->getKey())->sole();

    $component->callTableAction('completeInstallation', $installation);
    $component->callTableAction('passCommissioning', $installation)->assertNotified('Unable to update the installation');
    $component->callTableAction('failCommissioning', $installation, ['reason' => 'Drift']);

    expect($installation->fresh()->commissioning_status)->toBe(CommissioningStatus::Failed);

    $installation->checks()->update(['result' => InstallationCheckResult::Passed->value]);
    $component->callTableAction('passCommissioning', $installation);
    $component->callTableAction('customerRejects', $installation, ['reason' => 'Noise']);

    expect($installation->fresh()->customer_acceptance_status)->toBe(CustomerAcceptanceStatus::Rejected);
});
