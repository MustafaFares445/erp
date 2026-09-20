<?php

declare(strict_types=1);

use App\Enums\CustomerProfileChangeRequestStatus;
use App\Models\AuditLog;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\Crm\CustomerProfileChangeRequestService;
use App\Services\Crm\Exceptions\InvalidCustomerProfileChangeRequestTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

it('creates a pending change request without touching the customer profile', function (): void {
    $customer = CustomerProfile::factory()->create(['company_name' => 'Old Name']);

    $request = app(CustomerProfileChangeRequestService::class)->create(
        $customer,
        ['company_name' => 'New Name', 'city' => 'Sharjah'],
        [],
        null,
        'Company rebranded.',
    );

    expect($request->status)->toBe(CustomerProfileChangeRequestStatus::Pending)
        ->and($request->requested_changes)->toBe(['company_name' => 'New Name', 'city' => 'Sharjah'])
        ->and($customer->refresh()->company_name)->toBe('Old Name');
});

it('rejects a requested field outside the legal/company identity allow-list', function (): void {
    $customer = CustomerProfile::factory()->create();

    expect(fn () => app(CustomerProfileChangeRequestService::class)->create(
        $customer,
        ['is_active' => true],
    ))->toThrow(InvalidCustomerProfileChangeRequestTransition::class);

    expect($customer->changeRequests()->count())->toBe(0);
});

it('approves a request and atomically applies the requested changes', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create(['company_name' => 'Old Name', 'country' => 'AE']);

    $request = app(CustomerProfileChangeRequestService::class)->create(
        $customer,
        ['company_name' => 'New Name', 'country' => 'SA'],
    );

    $approved = app(CustomerProfileChangeRequestService::class)->approve($admin, $request, 'Verified with the client.');

    expect($approved->status)->toBe(CustomerProfileChangeRequestStatus::Approved)
        ->and($approved->reviewed_by)->toBe($admin->id)
        ->and($approved->applied_at)->not->toBeNull()
        ->and($customer->refresh()->company_name)->toBe('New Name')
        ->and($customer->country)->toBe('SA')
        ->and(AuditLog::query()->where('description', 'customer.change_request.approved')->value('causer_id'))->toBe($admin->id);
});

it('rejects a request and never mutates the customer profile', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create(['company_name' => 'Old Name']);

    $request = app(CustomerProfileChangeRequestService::class)->create(
        $customer,
        ['company_name' => 'New Name'],
    );

    $rejected = app(CustomerProfileChangeRequestService::class)->reject($admin, $request, 'Legal name mismatch.');

    expect($rejected->status)->toBe(CustomerProfileChangeRequestStatus::Rejected)
        ->and($rejected->review_note)->toBe('Legal name mismatch.')
        ->and($customer->refresh()->company_name)->toBe('Old Name');
});

it('cancels a pending request before review', function (): void {
    $requester = User::factory()->customer()->create();
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create(['user_id' => $requester->id, 'city' => 'Dubai']);

    $request = app(CustomerProfileChangeRequestService::class)->create(
        $customer,
        ['city' => 'Ajman'],
        [],
        $requester,
    );

    $cancelled = app(CustomerProfileChangeRequestService::class)->cancel($admin, $request);

    expect($cancelled->status)->toBe(CustomerProfileChangeRequestStatus::Cancelled)
        ->and($customer->refresh()->city)->toBe('Dubai');
});

it('replaces the legal document only when the request is approved', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();

    $request = app(CustomerProfileChangeRequestService::class)->create(
        $customer,
        [],
        ['tax_certificate' => UploadedFile::fake()->create('new-tax-certificate.pdf', 100, 'application/pdf')],
    );

    expect($customer->getFirstMedia('tax_certificate'))->toBeNull();

    app(CustomerProfileChangeRequestService::class)->approve($admin, $request);

    expect($customer->refresh()->getFirstMedia('tax_certificate'))->not->toBeNull()
        ->and($request->refresh()->getFirstMedia('tax_certificate'))->not->toBeNull();
});

it('refuses to review a request that already has a decision', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();

    $request = app(CustomerProfileChangeRequestService::class)->create($customer, ['city' => 'Dubai']);
    app(CustomerProfileChangeRequestService::class)->reject($admin, $request, 'No longer needed.');

    expect(fn () => app(CustomerProfileChangeRequestService::class)->approve($admin, $request->refresh()))
        ->toThrow(InvalidCustomerProfileChangeRequestTransition::class);
});
