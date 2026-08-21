<?php

declare(strict_types=1);

use App\Enums\AccountingPermission;
use App\Enums\DashboardRole;
use App\Models\ChartAccount;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\User;
use App\Policies\ChartAccountPolicy;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new AccountingPermissionSeeder)->run();
});

function userWithRole(DashboardRole $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user;
}

/*
 * The role matrix from contracts/permissions.md §2, asserted ability by ability.
 * `true` means the policy grants it; `false` means it must refuse.
 */
dataset('chartAccountMatrix', [
    'system admin' => [DashboardRole::SystemAdmin, ['viewAny' => true, 'create' => true, 'update' => true, 'delete' => true, 'viewLedger' => true]],
    'chief accountant' => [DashboardRole::ChiefAccountant, ['viewAny' => true, 'create' => true, 'update' => true, 'delete' => true, 'viewLedger' => true]],
    'accountant' => [DashboardRole::Accountant, ['viewAny' => true, 'create' => true, 'update' => true, 'delete' => true, 'viewLedger' => true]],
    'reviewer' => [DashboardRole::Reviewer, ['viewAny' => true, 'create' => false, 'update' => false, 'delete' => false, 'viewLedger' => true]],
]);

it('applies the chart account matrix', function (DashboardRole $role, array $expected): void {
    $user = userWithRole($role);
    $account = ChartAccount::factory()->create();

    foreach ($expected as $ability => $allowed) {
        expect($user->can($ability, $account))->toBe($allowed, sprintf('%s / %s', $role->value, $ability));
    }
})->with('chartAccountMatrix');

dataset('fiscalPeriodMatrix', [
    'system admin' => [DashboardRole::SystemAdmin, ['viewAny' => true, 'create' => true, 'update' => true, 'delete' => true, 'close' => true, 'reopen' => true]],
    'chief accountant' => [DashboardRole::ChiefAccountant, ['viewAny' => true, 'create' => true, 'update' => true, 'delete' => true, 'close' => true, 'reopen' => true]],
    'accountant' => [DashboardRole::Accountant, ['viewAny' => true, 'create' => false, 'update' => false, 'delete' => false, 'close' => false, 'reopen' => false]],
    'reviewer' => [DashboardRole::Reviewer, ['viewAny' => true, 'create' => false, 'update' => false, 'delete' => false, 'close' => false, 'reopen' => false]],
]);

it('applies the fiscal period matrix', function (DashboardRole $role, array $expected): void {
    $user = userWithRole($role);
    $period = FiscalPeriod::factory()->create();

    foreach ($expected as $ability => $allowed) {
        expect($user->can($ability, $period))->toBe($allowed, sprintf('%s / %s', $role->value, $ability));
    }
})->with('fiscalPeriodMatrix');

dataset('journalEntryDraftMatrix', [
    'system admin' => [DashboardRole::SystemAdmin, ['viewAny' => true, 'create' => true, 'update' => true, 'delete' => true, 'post' => true]],
    'chief accountant' => [DashboardRole::ChiefAccountant, ['viewAny' => true, 'create' => true, 'update' => true, 'delete' => true, 'post' => true]],
    'accountant' => [DashboardRole::Accountant, ['viewAny' => true, 'create' => true, 'update' => true, 'delete' => true, 'post' => true]],
    'reviewer' => [DashboardRole::Reviewer, ['viewAny' => true, 'create' => false, 'update' => false, 'delete' => false, 'post' => false]],
]);

it('applies the journal entry matrix to a draft', function (DashboardRole $role, array $expected): void {
    $user = userWithRole($role);
    $draft = JournalEntry::factory()->balanced()->create();

    foreach ($expected as $ability => $allowed) {
        expect($user->can($ability, $draft))->toBe($allowed, sprintf('%s / %s', $role->value, $ability));
    }
})->with('journalEntryDraftMatrix');

dataset('journalEntryPostedMatrix', [
    'system admin' => [DashboardRole::SystemAdmin, true],
    'chief accountant' => [DashboardRole::ChiefAccountant, true],
    'accountant' => [DashboardRole::Accountant, false],
    'reviewer' => [DashboardRole::Reviewer, false],
]);

it('allows reversal of a posted entry only for roles that hold the permission', function (DashboardRole $role, bool $canReverse): void {
    $user = userWithRole($role);
    $posted = JournalEntry::factory()->postedAndBalanced()->create();

    expect($user->can('reverse', $posted))->toBe($canReverse);
})->with('journalEntryPostedMatrix');

it('refuses to update or delete a posted entry for every role, including system admin', function (DashboardRole $role): void {
    $user = userWithRole($role);
    $posted = JournalEntry::factory()->postedAndBalanced()->create();

    // Immutability is an invariant, not a privilege (permissions.md R-1): no role
    // unlocks it, and the only route to changing a posted entry's effect is a
    // reversal.
    expect($user->can('update', $posted))->toBeFalse()
        ->and($user->can('delete', $posted))->toBeFalse()
        ->and($user->can('post', $posted))->toBeFalse();
})->with([
    [DashboardRole::SystemAdmin],
    [DashboardRole::ChiefAccountant],
    [DashboardRole::Accountant],
    [DashboardRole::Reviewer],
]);

it('refuses to reverse a draft for every role', function (DashboardRole $role): void {
    $user = userWithRole($role);
    $draft = JournalEntry::factory()->balanced()->create();

    expect($user->can('reverse', $draft))->toBeFalse();
})->with([
    [DashboardRole::SystemAdmin],
    [DashboardRole::ChiefAccountant],
    [DashboardRole::Accountant],
    [DashboardRole::Reviewer],
]);

describe('the three separations of duty', function (): void {
    it('does not let managing a journal entry imply posting it', function (): void {
        $user = User::factory()->create();
        $user->assignRole(DashboardRole::Reviewer->value);
        $user->givePermissionTo(AccountingPermission::JournalEntryManage->value);

        $draft = JournalEntry::factory()->balanced()->create();

        expect($user->can('update', $draft))->toBeTrue()
            ->and($user->can('post', $draft))->toBeFalse();
    });

    it('does not let posting imply reversing', function (): void {
        $user = User::factory()->create();
        $user->assignRole(DashboardRole::Reviewer->value);
        $user->givePermissionTo(AccountingPermission::JournalEntryPost->value);

        $draft = JournalEntry::factory()->balanced()->create();
        $posted = JournalEntry::factory()->postedAndBalanced()->create();

        expect($user->can('post', $draft))->toBeTrue()
            ->and($user->can('reverse', $posted))->toBeFalse();
    });

    it('does not let managing a fiscal period imply closing it', function (): void {
        $user = User::factory()->create();
        $user->assignRole(DashboardRole::Reviewer->value);
        $user->givePermissionTo(AccountingPermission::FiscalPeriodManage->value);

        $period = FiscalPeriod::factory()->create();

        expect($user->can('update', $period))->toBeTrue()
            ->and($user->can('close', $period))->toBeFalse();
    });

    it('does not let viewing accounts imply viewing balances', function (): void {
        // A non-admin base, because the Reviewer role already carries LedgerView
        // and a user-level revoke does not strip a role-granted permission.
        $user = User::factory()->employee()->create();
        $user->givePermissionTo(AccountingPermission::ChartAccountView->value);

        $account = ChartAccount::factory()->create();

        expect($user->can('viewAny', $account))->toBeTrue()
            ->and($user->can('viewLedger', $account))->toBeFalse();
    });
});

describe('admin bypass', function (): void {
    it('grants an admin with no fixed dashboard role everything', function (): void {
        $admin = User::factory()->admin()->create();
        $account = ChartAccount::factory()->create();
        $period = FiscalPeriod::factory()->create();
        $draft = JournalEntry::factory()->balanced()->create();

        expect($admin->can('create', $account))->toBeTrue()
            ->and($admin->can('close', $period))->toBeTrue()
            ->and($admin->can('post', $draft))->toBeTrue();
    });

    it('narrows an admin who holds any fixed dashboard role to explicit permissions', function (): void {
        $admin = User::factory()->admin()->create();
        $admin->assignRole(DashboardRole::Accountant->value);

        $period = FiscalPeriod::factory()->create();

        // Accountant does not hold fiscal-period.close, and holding a fixed role
        // means the isAdmin() bypass no longer applies.
        expect($admin->can('close', $period))->toBeFalse();
    });

    it('refuses a non-admin user with no accounting permission at all', function (): void {
        // `User::factory()` defaults to an admin user type, and an admin holding
        // no fixed dashboard role keeps the blanket bypass — so this needs a
        // non-admin base to be a meaningful test.
        $nobody = User::factory()->employee()->create();
        $account = ChartAccount::factory()->create();

        expect($nobody->can('viewAny', $account))->toBeFalse()
            ->and($nobody->can('create', $account))->toBeFalse();
    });
});

it('never allows a force delete', function (): void {
    $admin = User::factory()->admin()->create();
    $account = ChartAccount::factory()->create();
    $period = FiscalPeriod::factory()->create();
    $draft = JournalEntry::factory()->balanced()->create();

    expect($admin->can('forceDelete', $account))->toBeFalse()
        ->and($admin->can('forceDelete', $period))->toBeFalse()
        ->and($admin->can('forceDelete', $draft))->toBeFalse();
});

it('grants restore alongside manage, and single-record view alongside viewAny', function (): void {
    $chief = userWithRole(DashboardRole::ChiefAccountant);
    $reviewer = userWithRole(DashboardRole::Reviewer);

    $account = ChartAccount::factory()->create();
    $period = FiscalPeriod::factory()->create();

    // `restore` is the counterpart of the soft delete only ChartAccount has, and
    // it is gated on the same manage permission — restoring an account is an edit,
    // not a separate privilege.
    expect($chief->can('restore', $account))->toBeTrue()
        ->and($reviewer->can('restore', $account))->toBeFalse()
        // `view` mirrors `viewAny`: a role that can list can open a single record.
        ->and($reviewer->can('view', $period))->toBeTrue()
        ->and($reviewer->can('view', $account))->toBeTrue();
});

it('refuses an ability the permission map does not name', function (): void {
    $chief = userWithRole(DashboardRole::ChiefAccountant);

    // A Gate check for an ability absent from accountingPermissionMap() must be
    // refused rather than fall through to a default — an unmapped ability is a
    // programming mistake, and defaulting to allow would make it a silent one.
    $policy = app(ChartAccountPolicy::class);
    $authorize = new ReflectionMethod($policy, 'authorizeAccountingAbility');

    expect($authorize->invoke($policy, $chief, 'somethingNobodyMapped'))->toBeFalse();
});
