<?php

declare(strict_types=1);

use App\Models\ChartAccount;
use App\Models\FiscalPeriod;
use App\Models\PaymentMethod;
use App\Models\SalesSetting;
use Database\Seeders\ChartOfAccountsSeeder;
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

function configurePaymentAccounting(string $methodType = 'bank_transfer', float $taxPercent = 0.0): PaymentMethod
{
    (new ChartOfAccountsSeeder)->run();

    if (! FiscalPeriod::query()->exists()) {
        FiscalPeriod::factory()->create();
    }

    $account = static fn (string $code): int => (int) ChartAccount::query()
        ->where('code', $code)
        ->sole()
        ->getKey();

    SalesSetting::query()->firstOrCreate([], [
        'default_tax_percent' => number_format($taxPercent, 2, '.', ''),
        'default_quotation_validity_days' => 30,
        'receivable_account_id' => $account('1200'),
        'revenue_account_id' => $account('4100'),
        'deferred_tax_account_id' => $account('2350'),
        'tax_payable_account_id' => $account('2300'),
        'customer_deposits_account_id' => $account('2400'),
        'bad_debt_expense_account_id' => $account('6800'),
        'auto_apply_customer_deposits' => true,
    ]);

    return PaymentMethod::factory()->create([
        'type' => $methodType,
        'chart_account_id' => $account('1100'),
        'is_active' => true,
        'requires_proof' => false,
    ]);
}
