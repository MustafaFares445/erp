<?php

declare(strict_types=1);

use App\Enums\InstallationCheckResult;
use App\Enums\WarrantyEntitlementState;
use App\Enums\WarrantyStartTrigger;
use App\Services\Support\EquipmentInstallationService;
use App\Services\Support\InstallationProgressResolver;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InstallationFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
});

/** @param array<string, array{state:string}> $steps */
function stepStates(array $progress): array
{
    return collect($progress['steps'])->mapWithKeys(fn (array $step): array => [$step['key'] => $step['state']])->all();
}

it('describes an installation that has not started', function (): void {
    [, , $record] = InstallationFixtures::scenario();

    $progress = app(InstallationProgressResolver::class)->resolve($record);

    expect($progress['status'])->toBe('Installation not started')
        ->and($progress['blocker'])->toBeNull()
        ->and($progress['failed'])->toBeFalse()
        ->and(stepStates($progress)['equipment'])->toBe('current')
        ->and(array_keys(stepStates($progress)))->toBe(['equipment', 'installation', 'checklist', 'commissioning', 'acceptance', 'warranty']);

    $record->update(['serialized_inventory_unit_id' => null]);

    expect(app(InstallationProgressResolver::class)->resolve($record->fresh())['blocker'])->toBe('Link serialized equipment to this request first.');
});

it('walks the steps from pending installation to accepted', function (): void {
    [, , $record] = InstallationFixtures::scenario();
    $actor = InstallationFixtures::manager();
    $service = app(EquipmentInstallationService::class);
    $resolver = app(InstallationProgressResolver::class);
    $installation = $service->createForDeliveredEquipment($record, $actor);

    $progress = $resolver->resolve($record->fresh());
    expect($progress['status'])->toBe('Pending installation')
        ->and(stepStates($progress)['installation'])->toBe('current')
        ->and($progress['checks_total'])->toBe(6)
        ->and($progress['checks_done'])->toBe(0);

    $service->completeInstallation($installation, $actor);
    $progress = $resolver->resolve($record->fresh());
    expect($progress['status'])->toBe('Installation completed')
        ->and($progress['blocker'])->toBe('Checklist incomplete.')
        ->and(stepStates($progress)['commissioning'])->toBe('current');

    InstallationFixtures::passAllChecks($installation->fresh(), $actor);
    $progress = $resolver->resolve($record->fresh());
    expect($progress['blocker'])->toBeNull()
        ->and(stepStates($progress)['checklist'])->toBe('done');

    $service->completeCommissioning($installation, $actor);
    $progress = $resolver->resolve($record->fresh());
    expect($progress['status'])->toBe('Awaiting customer acceptance')
        ->and(stepStates($progress)['acceptance'])->toBe('current');

    $service->acceptByCustomer($installation, 'Dr. Salem', $actor);
    $progress = $resolver->resolve($record->fresh());
    expect($progress['status'])->toBe('Accepted')
        ->and(stepStates($progress)['acceptance'])->toBe('done')
        ->and($progress['failed'])->toBeFalse();
});

it('flags failed commissioning and a rejected installation as failures with a blocker', function (): void {
    [, , $record] = InstallationFixtures::scenario();
    $actor = InstallationFixtures::manager();
    $service = app(EquipmentInstallationService::class);
    $resolver = app(InstallationProgressResolver::class);
    $installation = $service->createForDeliveredEquipment($record, $actor);
    $service->completeInstallation($installation, $actor);
    $service->failCommissioning($installation, $actor, 'Drift');

    $progress = $resolver->resolve($record->fresh());
    expect($progress['status'])->toBe('Commissioning failed')
        ->and($progress['failed'])->toBeTrue()
        ->and($progress['blocker'])->toBe('Commissioning failed.')
        ->and(stepStates($progress)['commissioning'])->toBe('failed');

    InstallationFixtures::passAllChecks($installation->fresh(), $actor);
    $service->completeCommissioning($installation, $actor);
    $service->rejectByCustomer($installation, 'Noise', $actor);

    $progress = $resolver->resolve($record->fresh());
    expect($progress['status'])->toBe('Rejected')
        ->and($progress['failed'])->toBeTrue()
        ->and($progress['blocker'])->toBe('The customer rejected the installation.')
        ->and(stepStates($progress)['acceptance'])->toBe('failed');
});

it('reports the warranty activation result for each trigger state', function (): void {
    [$customer, $unit, $record] = InstallationFixtures::scenario();
    $resolver = app(InstallationProgressResolver::class);

    $warranty = fn (): array => collect($resolver->resolve($record->fresh())['steps'])->firstWhere('key', 'warranty');

    expect($warranty()['detail'])->toBe('No warranty policy applies.');

    $entitlement = InstallationFixtures::pendingEntitlement($customer, $unit, WarrantyStartTrigger::Commissioning);
    expect($warranty()['state'])->toBe('upcoming')
        ->and($warranty()['detail'])->toContain('Starts on');

    $entitlement->update(['state' => WarrantyEntitlementState::Ended]);
    expect($warranty()['detail'])->toBe('Ended');

    $entitlement->update(['state' => WarrantyEntitlementState::Active, 'starts_on' => today(), 'expires_on' => today()->addYear()]);
    expect($warranty()['state'])->toBe('done')
        ->and($warranty()['detail'])->toContain(today()->addYear()->toDateString());
});

it('normalises check result form state', function (): void {
    expect(InstallationCheckResult::fromState(InstallationCheckResult::Failed))->toBe(InstallationCheckResult::Failed)
        ->and(InstallationCheckResult::fromState('passed'))->toBe(InstallationCheckResult::Passed)
        ->and(InstallationCheckResult::fromState('nope'))->toBe(InstallationCheckResult::Pending)
        ->and(InstallationCheckResult::fromState(null))->toBe(InstallationCheckResult::Pending);
});
