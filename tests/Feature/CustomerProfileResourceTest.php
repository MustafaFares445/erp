<?php

declare(strict_types=1);

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Models\AuditLog;
use App\Models\CustomerProfile;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('creates a customer profile for a customer account and records the action', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = User::factory()->customer()->create();

    Livewire::actingAs($admin)
        ->test(CreateCustomer::class)
        ->fillForm([
            'user_id' => $customer->id,
            'customer_code' => 'CUST-001',
            'company_name' => 'Acme Trading',
            'address' => 'Damascus',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $profile = CustomerProfile::query()->sole();

    expect($profile->user->is($customer))->toBeTrue()
        ->and($profile->customer_code)->toBe('CUST-001')
        ->and(AuditLog::query()->where('action', 'customer.created')->value('actor_user_id'))->toBe($admin->id);
});

it('rejects duplicate customer codes', function (): void {
    $admin = User::factory()->admin()->create();
    CustomerProfile::factory()->create(['customer_code' => 'CUST-DUP']);
    $customer = User::factory()->customer()->create();

    Livewire::actingAs($admin)
        ->test(CreateCustomer::class)
        ->fillForm([
            'user_id' => $customer->id,
            'customer_code' => 'CUST-DUP',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasFormErrors(['customer_code']);

    expect(CustomerProfile::query()->where('customer_code', 'CUST-DUP')->count())->toBe(1);
});

it('lists customers by code and company name', function (): void {
    $admin = User::factory()->admin()->create();
    $matchingCustomer = CustomerProfile::factory()->create([
        'customer_code' => 'CUST-SEARCH',
        'company_name' => 'Searchable Company',
    ]);
    CustomerProfile::factory()->create(['company_name' => 'Other Company']);

    Livewire::actingAs($admin)
        ->test(ListCustomers::class)
        ->searchTable('CUST-SEARCH')
        ->assertCanSeeTableRecords([$matchingCustomer])
        ->searchTable('Searchable Company')
        ->assertCanSeeTableRecords([$matchingCustomer]);
});

it('deactivates and soft deletes a customer profile with audit entries', function (): void {
    $admin = User::factory()->admin()->create();
    $profile = CustomerProfile::factory()->create(['is_active' => true]);

    Livewire::actingAs($admin)
        ->test(EditCustomer::class, ['record' => $profile->getKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($profile->refresh()->is_active)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'customer.deactivated')->exists())->toBeTrue();

    Livewire::actingAs($admin)
        ->test(EditCustomer::class, ['record' => $profile->getKey()])
        ->callAction(DeleteAction::class)
        ->assertNotified();

    expect(CustomerProfile::query()->find($profile->id))->toBeNull()
        ->and(CustomerProfile::withTrashed()->find($profile->id))->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'customer.deleted')->exists())->toBeTrue();
});

it('denies customer administration to a customer-channel user', function (): void {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)->get(CustomerResource::getUrl('index'))->assertForbidden();
});
