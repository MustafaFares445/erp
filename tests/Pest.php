<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Models\ChartAccount;
use App\Models\SalesSetting;
use App\Models\User;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)->in('Feature', 'Unit');

beforeEach(function (): void {
    $token = ParallelTesting::token();

    if (is_string($token)) {
        $publicRoot = storage_path('framework/testing/disks/public-'.$token);

        File::ensureDirectoryExists($publicRoot);
        config()->set('filesystems.disks.public.root', $publicRoot);

        $localRoot = storage_path('framework/testing/disks/local-'.$token);

        File::ensureDirectoryExists($localRoot);
        config()->set('filesystems.disks.local.root', $localRoot);
    }

    Http::preventStrayRequests();

    if (method_exists(Process::class, 'preventStrayProcesses')) {
        Process::preventStrayProcesses();
    }

    Sleep::fake();
    $this->freezeTime();
});

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something(): void
{
    // ..
}

/**
 * Seeds the inventory permission catalogue and roles. The narrow, per-file
 * permission subsets granted on top of this (via a local role or a direct
 * `givePermissionTo()` call) are deliberate test fixtures proving specific
 * authorization boundaries, so this helper only covers the seeding step
 * common to all of them — it does not assign any role itself.
 */
function seedInventoryPermissions(): void
{
    (new InventoryPermissionSeeder)->run();
}

/**
 * Seeds accounting permissions and returns a user holding the Accountant
 * dashboard role.
 */
function actingAsAccountant(bool $admin = false): User
{
    (new AccountingPermissionSeeder)->run();
    $user = $admin ? User::factory()->admin()->create() : User::factory()->create();
    $user->assignRole(DashboardRole::Accountant->value);

    return $user;
}

/**
 * Seeds accounting permissions and returns a user holding the Chief
 * Accountant dashboard role.
 */
function actingAsChiefAccountant(bool $admin = false): User
{
    (new AccountingPermissionSeeder)->run();
    $user = $admin ? User::factory()->admin()->create() : User::factory()->create();
    $user->assignRole(DashboardRole::ChiefAccountant->value);

    return $user;
}

/**
 * Seeds the chart of accounts and points SalesSetting at the standard
 * posting accounts every accounting-adjacent test needs. Defaults to the
 * full six-account superset (codes 1200, 4100, 2350, 2300, 2400, 6800) —
 * setting an account a given test doesn't exercise is harmless, so this
 * one helper covers every call site's actual need rather than requiring
 * three different field-count variants.
 */
function seedPostingAccounts(): void
{
    (new ChartOfAccountsSeeder)->run();

    SalesSetting::current()->forceFill([
        'receivable_account_id' => ChartAccount::query()->where('code', '1200')->value('id'),
        'revenue_account_id' => ChartAccount::query()->where('code', '4100')->value('id'),
        'deferred_tax_account_id' => ChartAccount::query()->where('code', '2350')->value('id'),
        'tax_payable_account_id' => ChartAccount::query()->where('code', '2300')->value('id'),
        'customer_deposits_account_id' => ChartAccount::query()->where('code', '2400')->value('id'),
        'bad_debt_expense_account_id' => ChartAccount::query()->where('code', '6800')->value('id'),
    ])->save();
}
