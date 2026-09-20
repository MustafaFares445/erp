<?php

declare(strict_types=1);

use App\Enums\CustomerApprovalStatus;
use App\Enums\CustomerProvisioningSource;
use App\Enums\UserType;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\Crm\CustomerAccountProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->account = [
        'name' => 'Jane Buyer',
        'username' => 'jane-buyer',
        'email' => 'jane@acme.test',
        'password' => 'password123',
    ];

    $this->profile = [
        'company_name' => 'Acme Trading',
        'contact_is_self' => true,
        'is_active' => true,
    ];
});

it('creates a User and CustomerProfile atomically with an explicit customer code', function (): void {
    $profile = app(CustomerAccountProvisioningService::class)->provision(
        $this->account,
        [...$this->profile, 'customer_code' => 'CUST-777'],
        [],
        CustomerProvisioningSource::Dashboard,
    );

    expect($profile->customer_code)->toBe('CUST-777')
        ->and($profile->user->username)->toBe('jane-buyer')
        ->and($profile->user->user_type)->toBe(UserType::Customer)
        ->and($profile->is_active)->toBeTrue()
        ->and($profile->approval_status)->toBe(CustomerApprovalStatus::Approved);
});

it('auto-generates a customer code when none is given', function (): void {
    $profile = app(CustomerAccountProvisioningService::class)->provision(
        $this->account,
        $this->profile,
        [],
        CustomerProvisioningSource::JoinUs,
    );

    expect($profile->customer_code)->toStartWith('CUST-');
});

it('derives Pending/inactive from an inactive initial profile', function (): void {
    $profile = app(CustomerAccountProvisioningService::class)->provision(
        $this->account,
        [...$this->profile, 'is_active' => false, 'customer_code' => 'CUST-PEND'],
        [],
        CustomerProvisioningSource::JoinUs,
    );

    expect($profile->is_active)->toBeFalse()
        ->and($profile->approval_status)->toBe(CustomerApprovalStatus::Pending);
});

it('rolls back the User when the password is not a string', function (): void {
    $service = app(CustomerAccountProvisioningService::class);

    expect(fn (): CustomerProfile => $service->provision(
        [...$this->account, 'password' => null],
        [...$this->profile, 'customer_code' => 'CUST-BAD'],
        [],
        CustomerProvisioningSource::Dashboard,
    ))->toThrow(RuntimeException::class, 'Expected a string value for "password".');

    expect(User::query()->count())->toBe(0)
        ->and(CustomerProfile::query()->count())->toBe(0);
});

it('gives up generating a unique customer code once every attempt collides', function (): void {
    CustomerProfile::factory()->create(['customer_code' => 'CUST-1234']);
    $service = new CustomerAccountProvisioningService(static fn (int $min, int $max): int => 1234);

    expect(fn (): CustomerProfile => $service->provision(
        $this->account,
        $this->profile,
        [],
        CustomerProvisioningSource::JoinUs,
    ))->toThrow(RuntimeException::class, 'Unable to generate a unique customer code.');

    // The pre-existing conflicting profile's own user is the only one left —
    // the failed attempt's User insert rolled back with the rest of the transaction.
    expect(CustomerProfile::query()->count())->toBe(1)
        ->and(User::query()->count())->toBe(1);
});

it('stores separate contact details when the customer is not their own contact', function (): void {
    $profile = app(CustomerAccountProvisioningService::class)->provision(
        [...$this->account, 'username' => 'other-contact'],
        [
            ...$this->profile,
            'customer_code' => 'CUST-CONTACT',
            'contact_is_self' => false,
            'contact_name' => 'Accounts Payable',
            'contact_phone' => '+1000000',
            'contact_email' => 'ap@acme.test',
        ],
        [],
        CustomerProvisioningSource::Dashboard,
    );

    expect($profile->contact_is_self)->toBeFalse()
        ->and($profile->contact_name)->toBe('Accounts Payable')
        ->and($profile->contact_email)->toBe('ap@acme.test');
});
