<?php

declare(strict_types=1);

use App\Enums\CustomerApprovalStatus;
use App\Models\AuditLog;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\Crm\CustomerApprovalService;
use App\Services\Crm\Exceptions\InvalidCustomerApprovalTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('approves a pending customer, activating it and recording the reviewer', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->pending()->create();

    $approved = app(CustomerApprovalService::class)->approve($admin, $customer, 'Looks good');

    expect($approved->approval_status)->toBe(CustomerApprovalStatus::Approved)
        ->and($approved->is_active)->toBeTrue()
        ->and($approved->reviewed_by)->toBe($admin->id)
        ->and($approved->reviewed_at)->not->toBeNull()
        ->and($approved->review_note)->toBe('Looks good')
        ->and(AuditLog::query()->where('description', 'customer.approved')->value('causer_id'))->toBe($admin->id);
});

it('approves a customer whose changes were requested', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->changesRequested()->create();

    $approved = app(CustomerApprovalService::class)->approve($admin, $customer);

    expect($approved->approval_status)->toBe(CustomerApprovalStatus::Approved)
        ->and($approved->is_active)->toBeTrue();
});

it('requests changes on a pending or approved customer with a required note', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();

    $updated = app(CustomerApprovalService::class)->requestChanges($admin, $customer, 'Please upload a valid tax certificate.');

    expect($updated->approval_status)->toBe(CustomerApprovalStatus::ChangesRequested)
        ->and($updated->is_active)->toBeFalse()
        ->and($updated->review_note)->toBe('Please upload a valid tax certificate.')
        ->and(AuditLog::query()->where('description', 'customer.changes_requested')->exists())->toBeTrue();
});

it('rejects a customer from any non-terminal state with a reason', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();

    $rejected = app(CustomerApprovalService::class)->reject($admin, $customer, 'Documents could not be verified.');

    expect($rejected->approval_status)->toBe(CustomerApprovalStatus::Rejected)
        ->and($rejected->is_active)->toBeFalse()
        ->and(AuditLog::query()->where('description', 'customer.rejected')->exists())->toBeTrue();
});

it('reactivates a rejected customer back to Pending', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->rejected()->create();

    $reactivated = app(CustomerApprovalService::class)->reactivate($admin, $customer);

    expect($reactivated->approval_status)->toBe(CustomerApprovalStatus::Pending)
        ->and($reactivated->is_active)->toBeFalse();
});

it('refuses to approve an already-approved customer directly', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create(['approval_status' => CustomerApprovalStatus::Approved]);

    expect(fn () => app(CustomerApprovalService::class)->approve($admin, $customer))
        ->toThrow(InvalidCustomerApprovalTransition::class);
});

it('refuses to reactivate a customer that is not rejected', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->pending()->create();

    expect(fn () => app(CustomerApprovalService::class)->reactivate($admin, $customer))
        ->toThrow(InvalidCustomerApprovalTransition::class);
});
