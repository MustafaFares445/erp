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
| Test Classification
|--------------------------------------------------------------------------
|
| Only Feature tests boot the Laravel application. Unit tests remain plain
| Pest/PHPUnit tests unless a specific file explicitly opts into Laravel.
|
| Coverage-only suites remain part of the authoritative coverage gate but are
| excluded from the normal fast behavioral feedback loop.
|
*/

pest()
    ->extend(TestCase::class)
    ->in('Feature')
    ->beforeEach(function (): void {
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

pest()->group('coverage-only')->in('Feature/Coverage', 'Feature/UnitIntegration/Coverage', 'Unit/Coverage');
pest()->group('architecture')->in('Unit/ArchTest.php');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Project Test Helpers
|--------------------------------------------------------------------------
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
