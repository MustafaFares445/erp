<?php

declare(strict_types=1);

use App\Models\CustomerProfile;
use App\Services\Sales\CustomerOrderingPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows an approved active customer to request a quotation', function (): void {
    $customer = CustomerProfile::factory()->create();

    expect(app(CustomerOrderingPolicyService::class)->canRequestQuotation($customer))->toBeTrue();
});

it('refuses a pending customer any commercial capability', function (): void {
    $customer = CustomerProfile::factory()->pending()->create();

    $policy = app(CustomerOrderingPolicyService::class);

    expect($policy->canRequestQuotation($customer))->toBeFalse()
        ->and($policy->canDirectOrder($customer))->toBeFalse();
});

it('only allows direct ordering when approved and explicitly flagged', function (): void {
    $policy = app(CustomerOrderingPolicyService::class);

    $approvedWithoutFlag = CustomerProfile::factory()->create(['allow_direct_orders' => false]);
    $approvedWithFlag = CustomerProfile::factory()->create(['allow_direct_orders' => true]);

    expect($policy->canDirectOrder($approvedWithoutFlag))->toBeFalse()
        ->and($policy->canDirectOrder($approvedWithFlag))->toBeTrue();
});
