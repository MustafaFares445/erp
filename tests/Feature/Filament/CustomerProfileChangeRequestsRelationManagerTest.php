<?php

declare(strict_types=1);

use App\Enums\CustomerProfileChangeRequestStatus;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\CustomerProfileChangeRequestsRelationManager;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\Crm\CustomerProfileChangeRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('approves a change request from the customer view page', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create(['company_name' => 'Old Name']);
    $request = app(CustomerProfileChangeRequestService::class)->create($customer, ['company_name' => 'New Name']);

    Livewire::actingAs($admin)
        ->test(CustomerProfileChangeRequestsRelationManager::class, [
            'ownerRecord' => $customer,
            'pageClass' => ViewCustomer::class,
        ])
        ->callTableAction('approveChangeRequest', $request, ['note' => 'Confirmed by phone.'])
        ->assertNotified();

    expect($request->refresh()->status)->toBe(CustomerProfileChangeRequestStatus::Approved)
        ->and($customer->refresh()->company_name)->toBe('New Name');
});

it('rejects a change request with a required reason', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create(['company_name' => 'Old Name']);
    $request = app(CustomerProfileChangeRequestService::class)->create($customer, ['company_name' => 'New Name']);

    Livewire::actingAs($admin)
        ->test(CustomerProfileChangeRequestsRelationManager::class, [
            'ownerRecord' => $customer,
            'pageClass' => ViewCustomer::class,
        ])
        ->callTableAction('rejectChangeRequest', $request, ['note' => 'Not verifiable.'])
        ->assertNotified();

    expect($request->refresh()->status)->toBe(CustomerProfileChangeRequestStatus::Rejected)
        ->and($customer->refresh()->company_name)->toBe('Old Name');
});

it('hides review actions once a request is already decided', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();
    $request = app(CustomerProfileChangeRequestService::class)->create($customer, ['city' => 'Dubai']);
    app(CustomerProfileChangeRequestService::class)->reject($admin, $request, 'No longer needed.');

    Livewire::actingAs($admin)
        ->test(CustomerProfileChangeRequestsRelationManager::class, [
            'ownerRecord' => $customer,
            'pageClass' => ViewCustomer::class,
        ])
        ->assertTableActionHidden('approveChangeRequest', $request->refresh())
        ->assertTableActionHidden('rejectChangeRequest', $request->refresh());
});
