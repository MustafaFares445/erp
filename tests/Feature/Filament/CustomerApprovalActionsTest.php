<?php

declare(strict_types=1);

use App\Enums\CustomerApprovalStatus;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('approves a pending customer from the view page', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->pending()->create();

    Livewire::actingAs($admin)
        ->test(ViewCustomer::class, ['record' => $customer->getKey()])
        ->callAction('approve', ['note' => 'All documents verified.'])
        ->assertNotified();

    expect($customer->refresh()->approval_status)->toBe(CustomerApprovalStatus::Approved)
        ->and($customer->is_active)->toBeTrue();
});

it('hides the approve action once already approved', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();

    Livewire::actingAs($admin)
        ->test(ViewCustomer::class, ['record' => $customer->getKey()])
        ->assertActionHidden('approve');
});

it('requests changes on a customer from the customers table', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();

    Livewire::actingAs($admin)
        ->test(ListCustomers::class)
        ->callTableAction('requestChanges', $customer, ['note' => 'Please re-upload the tax certificate.'])
        ->assertNotified();

    expect($customer->refresh()->approval_status)->toBe(CustomerApprovalStatus::ChangesRequested);
});

it('rejects a customer with a required reason', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();

    Livewire::actingAs($admin)
        ->test(ListCustomers::class)
        ->callTableAction('rejectApproval', $customer, ['note' => 'Failed verification.'])
        ->assertNotified();

    expect($customer->refresh()->approval_status)->toBe(CustomerApprovalStatus::Rejected);
});

it('reactivates a rejected customer back to Pending', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->rejected()->create();

    Livewire::actingAs($admin)
        ->test(ListCustomers::class)
        ->callTableAction('reactivate', $customer)
        ->assertNotified();

    expect($customer->refresh()->approval_status)->toBe(CustomerApprovalStatus::Pending);
});
