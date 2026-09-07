<?php

declare(strict_types=1);

use App\Enums\AccountingPermission;
use App\Enums\DashboardRole;
use App\Models\ReceivableWriteOff;
use App\Models\User;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new AccountingPermissionSeeder)->run();
});

it('keeps record and approve permissions operationally separate', function (): void {
    // Every user is given the Reviewer role first: `User::factory()` defaults
    // to an admin user type, and an admin holding no fixed dashboard role
    // keeps the blanket admin-bypass (permissions.md §4), which would make
    // this scenario meaningless. Reviewer carries neither WriteOffRecord nor
    // WriteOffApprove, so it narrows each user down to exactly what is
    // granted below — mirroring the "three separations of duty" pattern in
    // tests/Unit/Policies/AccountingPolicyTest.php.
    $maker = User::factory()->create();
    $maker->assignRole(DashboardRole::Reviewer->value);

    $checker = User::factory()->create();
    $checker->assignRole(DashboardRole::Reviewer->value);

    $recordOnly = User::factory()->create();
    $recordOnly->assignRole(DashboardRole::Reviewer->value);
    $recordOnly->givePermissionTo(AccountingPermission::WriteOffRecord->value);

    $approveOnly = User::factory()->create();
    $approveOnly->assignRole(DashboardRole::Reviewer->value);
    $approveOnly->givePermissionTo(AccountingPermission::WriteOffApprove->value);

    $writeOff = ReceivableWriteOff::factory()->create([
        'recorded_by' => $maker->getKey(),
    ]);

    expect($recordOnly->can('viewAny', ReceivableWriteOff::class))->toBeTrue()
        ->and($recordOnly->can('view', $writeOff))->toBeTrue()
        ->and($recordOnly->can('create', ReceivableWriteOff::class))->toBeTrue()
        ->and($recordOnly->can('update', $writeOff))->toBeTrue()
        ->and($recordOnly->can('cancel', $writeOff))->toBeTrue()
        ->and($recordOnly->can('approve', $writeOff))->toBeFalse()
        ->and($recordOnly->can('delete', $writeOff))->toBeFalse()
        ->and($approveOnly->can('viewAny', ReceivableWriteOff::class))->toBeFalse()
        ->and($approveOnly->can('create', ReceivableWriteOff::class))->toBeFalse()
        ->and($approveOnly->can('approve', $writeOff))->toBeTrue()
        ->and($checker->can('approve', $writeOff))->toBeFalse();
});
