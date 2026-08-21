<?php

declare(strict_types=1);

use App\Enums\AccountElement;
use App\Enums\DashboardRole;
use App\Models\AccountType;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountService;
use App\Services\Accounting\Exceptions\AccountHierarchyCycle;
use App\Services\Accounting\Exceptions\AccountNotDeletable;
use App\Services\Accounting\Exceptions\AccountNotPostable;
use Database\Factories\AccountTypeFactory;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new AccountingPermissionSeeder)->run();

    $this->service = app(ChartOfAccountService::class);

    $this->accountant = User::factory()->create();
    $this->accountant->assignRole(DashboardRole::Accountant->value);
    $this->actingAs($this->accountant);

    $this->assetType = AccountTypeFactory::existingOrNew(AccountElement::Asset);
});

it('creates a postable account with blameable columns', function (): void {
    $account = $this->service->create($this->accountant, [
        'account_type_id' => (int) $this->assetType->getKey(),
        'code' => '1100',
        'name' => 'Cash on Hand',
    ]);

    expect($account->code)->toBe('1100')
        ->and($account->is_postable)->toBeTrue()
        ->and($account->is_active)->toBeTrue()
        ->and($account->created_by)->toBe($this->accountant->getKey());
});

it('refuses a duplicate code', function (): void {
    $this->service->create($this->accountant, [
        'account_type_id' => (int) $this->assetType->getKey(),
        'code' => '1100',
        'name' => 'Cash on Hand',
    ]);

    expect(fn (): ChartAccount => $this->service->create($this->accountant, [
        'account_type_id' => (int) $this->assetType->getKey(),
        'code' => '1100',
        'name' => 'Duplicate',
    ]))->toThrow(UniqueConstraintViolationException::class);

    expect(ChartAccount::query()->where('code', '1100')->count())->toBe(1);
});

it('clears is_postable on the parent when it gains its first child', function (): void {
    $parent = $this->service->create($this->accountant, [
        'account_type_id' => (int) $this->assetType->getKey(),
        'code' => '1000',
        'name' => 'Assets',
    ]);

    expect($parent->is_postable)->toBeTrue();

    $this->service->create($this->accountant, [
        'account_type_id' => (int) $this->assetType->getKey(),
        'parent_id' => (int) $parent->getKey(),
        'code' => '1100',
        'name' => 'Cash on Hand',
    ]);

    expect($parent->fresh()->is_postable)->toBeFalse();
});

it('refuses to make an account with children postable', function (): void {
    $parent = ChartAccount::factory()->header()->create(['code' => '1000']);
    ChartAccount::factory()->create(['parent_id' => $parent->getKey(), 'code' => '1100']);

    expect(fn (): ChartAccount => $this->service->update($this->accountant, $parent, ['is_postable' => true]))
        ->toThrow(AccountNotPostable::class, 'Post to one of its sub-accounts');

    expect($parent->fresh()->is_postable)->toBeFalse();
});

it('refuses a parent that is the account itself', function (): void {
    $account = ChartAccount::factory()->create(['code' => '1100']);

    expect(fn (): ChartAccount => $this->service->update($this->accountant, $account, [
        'parent_id' => (int) $account->getKey(),
    ]))->toThrow(AccountHierarchyCycle::class);
});

it('refuses a parent that is one of the accounts own descendants', function (): void {
    $grandparent = ChartAccount::factory()->header()->create(['code' => '1000']);
    $parent = ChartAccount::factory()->header()->create(['code' => '1100', 'parent_id' => $grandparent->getKey()]);
    $child = ChartAccount::factory()->create(['code' => '1110', 'parent_id' => $parent->getKey()]);

    expect(fn (): ChartAccount => $this->service->update($this->accountant, $grandparent, [
        'parent_id' => (int) $child->getKey(),
    ]))->toThrow(AccountHierarchyCycle::class, '1000');

    expect($grandparent->fresh()->parent_id)->toBeNull();
});

it('allows moving an account under an unrelated parent', function (): void {
    $newParent = ChartAccount::factory()->header()->create(['code' => '2000']);
    $account = ChartAccount::factory()->create(['code' => '1100']);

    $moved = $this->service->update($this->accountant, $account, [
        'parent_id' => (int) $newParent->getKey(),
    ]);

    expect($moved->parent_id)->toBe($newParent->getKey());
});

it('refuses to delete an account with children', function (): void {
    $parent = ChartAccount::factory()->header()->create(['code' => '1000']);
    ChartAccount::factory()->create(['parent_id' => $parent->getKey(), 'code' => '1100']);

    expect(fn () => $this->service->delete($this->accountant, $parent))
        ->toThrow(AccountNotDeletable::class, 'sub-accounts');

    expect(ChartAccount::query()->whereKey($parent->getKey())->exists())->toBeTrue();
});

it('refuses to delete an account used by a posted journal line', function (): void {
    $entry = JournalEntry::factory()->postedAndBalanced()->create();
    $account = $entry->lines()->firstOrFail()->chartAccount;

    expect(fn () => $this->service->delete($this->accountant, $account))
        ->toThrow(AccountNotDeletable::class, 'Mark it inactive instead');
});

it('refuses to delete an account used by a draft journal line', function (): void {
    $entry = JournalEntry::factory()->balanced()->create();
    $account = $entry->lines()->firstOrFail()->chartAccount;

    expect(fn () => $this->service->delete($this->accountant, $account))
        ->toThrow(AccountNotDeletable::class);
});

it('deletes an unused leaf account', function (): void {
    $account = ChartAccount::factory()->create(['code' => '1100']);

    $this->service->delete($this->accountant, $account);

    expect(ChartAccount::query()->whereKey($account->getKey())->exists())->toBeFalse()
        ->and(ChartAccount::withTrashed()->whereKey($account->getKey())->exists())->toBeTrue();
});

it('allows marking an account with posted history inactive', function (): void {
    $entry = JournalEntry::factory()->postedAndBalanced()->create();
    $account = $entry->lines()->firstOrFail()->chartAccount;

    $updated = $this->service->update($this->accountant, $account, ['is_active' => false]);

    expect($updated->is_active)->toBeFalse()
        ->and($entry->fresh()->lines()->count())->toBe(2);
});

it('refuses a user without the manage permission', function (): void {
    $reviewer = User::factory()->create();
    $reviewer->assignRole(DashboardRole::Reviewer->value);

    expect(fn (): ChartAccount => $this->service->create($reviewer, [
        'account_type_id' => (int) $this->assetType->getKey(),
        'code' => '1100',
        'name' => 'Cash on Hand',
    ]))->toThrow(AuthorizationException::class);
});

it('resolves self and descendant ids across three levels', function (): void {
    $root = ChartAccount::factory()->header()->create(['code' => '1000']);
    $mid = ChartAccount::factory()->header()->create(['code' => '1100', 'parent_id' => $root->getKey()]);
    $leafA = ChartAccount::factory()->create(['code' => '1110', 'parent_id' => $mid->getKey()]);
    $leafB = ChartAccount::factory()->create(['code' => '1120', 'parent_id' => $mid->getKey()]);
    $unrelated = ChartAccount::factory()->create(['code' => '2000']);

    $ids = $root->selfAndDescendantIds();

    expect($ids)->toHaveCount(4)
        ->and($ids)->toContain((int) $root->getKey(), (int) $mid->getKey(), (int) $leafA->getKey(), (int) $leafB->getKey())
        ->and($ids)->not->toContain((int) $unrelated->getKey());
});

it('does not loop forever if a cycle is introduced by a direct write', function (): void {
    $a = ChartAccount::factory()->header()->create(['code' => '1000']);
    $b = ChartAccount::factory()->header()->create(['code' => '1100', 'parent_id' => $a->getKey()]);

    // Bypasses the service guard entirely, which is the only way this state can
    // exist. The walk must terminate rather than exhaust memory.
    $a->forceFill(['parent_id' => $b->getKey()])->saveQuietly();

    expect($a->fresh()->selfAndDescendantIds())->toHaveCount(2);
});

it('keeps exactly five account types available', function (): void {
    foreach (AccountElement::cases() as $element) {
        AccountTypeFactory::existingOrNew($element);
    }

    expect(AccountType::query()->count())->toBe(5);
});
