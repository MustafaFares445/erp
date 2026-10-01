<?php

declare(strict_types=1);

use App\Enums\WarrantyClaimDecision;
use App\Enums\WarrantyCoverageSource;
use App\Enums\WarrantyRecoveryStatus;
use App\Filament\Resources\MaintenanceRequests\Pages\ViewMaintenanceRequest;
use App\Models\MaintenanceRecord;
use App\Models\User;
use App\Services\Support\WarrantyRecoveryService;
use Database\Seeders\SupportPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
});

function recoveryManager(): User
{
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    return $manager;
}

function manufacturerRecoveryRecord(): MaintenanceRecord
{
    return MaintenanceRecord::factory()->create([
        'coverage_decision' => WarrantyClaimDecision::ThirdPartyWarranty,
        'coverage_source' => WarrantyCoverageSource::ManufacturerWarranty,
        'coverage_reason' => 'Manufacturer accepted responsibility for this component family.',
        'coverage_decided_at' => now(),
    ]);
}

it('tracks a manufacturer recovery from draft through partial receipt to full receipt', function (): void {
    $manager = recoveryManager();
    $record = manufacturerRecoveryRecord();
    $service = app(WarrantyRecoveryService::class);

    $claim = $service->create($record, [
        'counterparty_name' => 'Acme Medical Manufacturing',
        'currency' => 'AED',
        'claimed_amount_minor' => 10000,
        'external_reference' => 'MFG-100',
    ], $manager);

    expect($claim->status)->toBe(WarrantyRecoveryStatus::Draft)
        ->and($claim->outstandingMinor())->toBe(10000);

    $claim = $service->submit($claim, $manager, 'MFG-100');
    expect($claim->status)->toBe(WarrantyRecoveryStatus::Submitted);

    $claim = $service->approve($claim, 8000, $manager);
    expect($claim->status)->toBe(WarrantyRecoveryStatus::Approved)
        ->and($claim->outstandingMinor())->toBe(8000);

    $claim = $service->recordReceipt($claim, 3000, $manager);
    expect($claim->status)->toBe(WarrantyRecoveryStatus::PartiallyReceived)
        ->and($claim->received_amount_minor)->toBe(3000)
        ->and($claim->outstandingMinor())->toBe(5000);

    $claim = $service->recordReceipt($claim, 5000, $manager);
    expect($claim->status)->toBe(WarrantyRecoveryStatus::Received)
        ->and($claim->received_amount_minor)->toBe(8000)
        ->and($claim->outstandingMinor())->toBe(0)
        ->and($claim->received_at)->not->toBeNull();
});

it('executes the third-party recovery actions from the maintenance workspace', function (): void {
    $manager = recoveryManager();
    $record = manufacturerRecoveryRecord();

    Livewire::actingAs($manager)
        ->test(ViewMaintenanceRequest::class, ['record' => $record->getRouteKey()])
        ->callAction(TestAction::make('createWarrantyRecovery'), [
            'counterparty_name' => 'Acme Medical Manufacturing',
            'claimed_amount' => 120,
            'currency' => 'AED',
            'external_reference' => 'MFG-UI-1',
        ])
        ->assertHasNoActionErrors();

    expect($record->warrantyRecoveryClaim()->firstOrFail()->status)->toBe(WarrantyRecoveryStatus::Draft);

    Livewire::actingAs($manager)
        ->test(ViewMaintenanceRequest::class, ['record' => $record->getRouteKey()])
        ->callAction(TestAction::make('submitWarrantyRecovery'), [
            'external_reference' => 'MFG-UI-1',
        ])
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('decideWarrantyRecovery'), [
            'decision' => 'approved',
            'approved_amount' => 100,
        ])
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('recordWarrantyRecoveryReceipt'), [
            'received_amount' => 100,
        ])
        ->assertHasNoActionErrors();

    $claim = $record->warrantyRecoveryClaim()->firstOrFail();

    expect($claim->status)->toBe(WarrantyRecoveryStatus::Received)
        ->and($claim->claimed_amount_minor)->toBe(12000)
        ->and($claim->approved_amount_minor)->toBe(10000)
        ->and($claim->received_amount_minor)->toBe(10000);
});
