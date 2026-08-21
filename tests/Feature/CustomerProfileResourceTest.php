<?php

declare(strict_types=1);

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\Schemas\CustomerInfolist;
use App\Models\AuditLog;
use App\Models\CustomerProfile;
use App\Models\Package;
use App\Models\User;
use App\Models\Warehouse;
use App\Policies\CustomerProfilePolicy;
use Database\Seeders\CrmPermissionSeeder;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Schema;
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
        ->and(AuditLog::query()->where('description', 'customer.created')->value('causer_id'))->toBe($admin->id);
});

it('stores delivery coordinates selected through the dashboard map picker', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = User::factory()->customer()->create();

    Livewire::actingAs($admin)
        ->test(CreateCustomer::class)
        ->assertSee('Search for an address...')
        ->assertSee('customer-location-picker__map')
        ->assertDontSee('Latitude')
        ->assertDontSee('Longitude')
        ->fillForm([
            'user_id' => $customer->id,
            'customer_code' => 'CUST-MAP-001',
            'latitude' => '33.5138000',
            'longitude' => '36.2765000',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $profile = CustomerProfile::query()->sole();

    expect((float) $profile->latitude)->toBe(33.5138)
        ->and((float) $profile->longitude)->toBe(36.2765);
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
        ->and(AuditLog::query()->where('description', 'customer.deactivated')->exists())->toBeTrue();

    Livewire::actingAs($admin)
        ->test(EditCustomer::class, ['record' => $profile->getKey()])
        ->callAction(DeleteAction::class)
        ->assertNotified();

    expect(CustomerProfile::query()->find($profile->id))->toBeNull()
        ->and(CustomerProfile::withTrashed()->find($profile->id))->not->toBeNull()
        ->and(AuditLog::query()->where('description', 'customer.deleted')->exists())->toBeTrue();
});

it('denies customer administration to a customer-channel user', function (): void {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)->get(CustomerResource::getUrl('index'))->assertForbidden();
});

it('enforces the CRM customer policy matrix for record and bulk actions', function (): void {
    (new CrmPermissionSeeder)->run();

    $systemAdmin = User::factory()->admin()->create();
    $systemAdmin->assignRole('System Admin');

    $crmManager = User::factory()->admin()->create();
    $crmManager->assignRole('CRM Manager');

    $pricingManager = User::factory()->admin()->create();
    $pricingManager->assignRole('Pricing Manager');

    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');

    $policy = app(CustomerProfilePolicy::class);

    expect($policy->update($systemAdmin))->toBeTrue()
        ->and($policy->restoreAny($systemAdmin))->toBeTrue()
        ->and($policy->update($crmManager))->toBeTrue()
        ->and($policy->deleteAny($crmManager))->toBeTrue()
        ->and($policy->restore($crmManager))->toBeFalse()
        ->and($policy->update($pricingManager))->toBeFalse()
        ->and($policy->view($pricingManager))->toBeTrue()
        ->and($policy->deleteAny($reviewer))->toBeFalse()
        ->and($policy->viewAny($reviewer))->toBeTrue();
});

it('renders the customer view action and infolist and exposes model relations', function (): void {
    $admin = User::factory()->admin()->create();
    $profile = CustomerProfile::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $package = Package::factory()->for($warehouse)->create();

    Livewire::actingAs($admin)
        ->test(ViewCustomer::class, ['record' => $profile->getKey()])
        ->assertActionVisible(EditAction::class);

    expect(CustomerResource::getNavigationLabel())->toBe(__('admin.resources.customers'))
        ->and(CustomerInfolist::configure(Schema::make())->getComponents())->not->toBeEmpty()
        ->and($profile->user->customerProfile->is($profile))->toBeTrue()
        ->and($warehouse->packages->contains(fn (Package $candidate): bool => $candidate->is($package)))->toBeTrue();
});

it('records a normal customer update separately from deactivation', function (): void {
    $admin = User::factory()->admin()->create();
    $profile = CustomerProfile::factory()->create(['company_name' => 'Before']);

    Livewire::actingAs($admin)
        ->test(EditCustomer::class, ['record' => $profile->getKey()])
        ->fillForm(['company_name' => 'After'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($profile->refresh()->company_name)->toBe('After')
        ->and(AuditLog::query()->where('description', 'customer.updated')->exists())->toBeTrue();
});

it('does not expose customer payment terms through the CRM customer model or page', function (): void {
    $admin = User::factory()->admin()->create();

    expect((new CustomerProfile)->isFillable('default_payment_term_id'))->toBeFalse();

    Livewire::actingAs($admin)
        ->test(CreateCustomer::class)
        ->assertDontSee('Payment term');
});

it('falls back to the base record update when handling a non-customer-profile model', function (): void {
    $admin = User::factory()->admin()->create();
    $profile = CustomerProfile::factory()->create();
    $otherUser = User::factory()->create(['name' => 'Before fallback']);

    $editCustomer = Livewire::actingAs($admin)
        ->test(EditCustomer::class, ['record' => $profile->getKey()])
        ->instance();

    $handleRecordUpdate = new ReflectionMethod(EditCustomer::class, 'handleRecordUpdate');
    $handleRecordUpdate->invoke($editCustomer, $otherUser, ['name' => 'After fallback']);

    expect($otherUser->refresh()->name)->toBe('After fallback');
});
