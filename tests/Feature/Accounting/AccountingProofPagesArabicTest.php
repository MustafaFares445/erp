<?php

declare(strict_types=1);

use App\Filament\Resources\AccountsPayable\AccountsPayableResource;
use App\Filament\Resources\AccountsReceivable\AccountsReceivableResource;
use App\Filament\Resources\Taxes\Pages\ViewTaxRegister;
use App\Models\ChartAccount;
use App\Models\SalesSetting;
use App\Models\User;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return list<string> */
function accountingProofPageViews(): array
{
    return [
        resource_path('views/filament/resources/accounts-receivable/pages/list-accounts-receivable.blade.php'),
        resource_path('views/filament/resources/accounts-payable/pages/list-accounts-payable.blade.php'),
        resource_path('views/filament/resources/taxes/pages/view-tax-register.blade.php'),
    ];
}

beforeEach(function (): void {
    (new ChartOfAccountsSeeder)->run();
    (new AccountingPermissionSeeder)->run();

    SalesSetting::current()->forceFill([
        'receivable_account_id' => ChartAccount::query()->where('code', '1200')->sole()->getKey(),
        'deferred_tax_account_id' => ChartAccount::query()->where('code', '2350')->sole()->getKey(),
        'tax_payable_account_id' => ChartAccount::query()->where('code', '2300')->sole()->getKey(),
    ])->save();

    $this->arabicAdmin = User::factory()->admin()->create(['locale' => 'ar']);
});

it('renders the receivable, payable and tax register proof pages in Arabic', function (string $url, array $arabic, array $english): void {
    $response = $this->actingAs($this->arabicAdmin)->get($url)->assertOk();

    foreach ($arabic as $text) {
        $response->assertSee($text);
    }

    foreach ($english as $text) {
        $response->assertDontSee($text);
    }
})->with([
    'accounts receivable' => [
        fn (): string => AccountsReceivableResource::getUrl(),
        ['أعمار الذمم المدينة', 'كما في', 'إثبات المطابقة', 'مطابق'],
        ['Receivables ageing', 'Reconciliation proof', 'AR control account', 'View detail'],
    ],
    'accounts payable' => [
        fn (): string => AccountsPayableResource::getUrl(),
        ['أعمار الذمم الدائنة', 'إثبات تطابق الذمم'],
        ['Payable aging', 'Tie-out proof', 'Payable control account'],
    ],
    'tax register' => [
        fn (): string => ViewTaxRegister::getUrl(),
        ['سجل الضرائب — من'],
        ['Tax register —', 'Output tax charged (deferred)', 'Net position', 'No tax entries in this period.'],
    ],
]);

it('has an Arabic translation with matching placeholders for every string on the proof pages', function (): void {
    $arabic = json_decode((string) file_get_contents(lang_path('ar.json')), true, flags: JSON_THROW_ON_ERROR);
    $problems = [];

    foreach (accountingProofPageViews() as $view) {
        preg_match_all("/__\\('((?:[^'\\\\]|\\\\.)+)'/", (string) file_get_contents($view), $matches);

        foreach ($matches[1] as $key) {
            $key = stripslashes($key);
            $translation = $arabic[$key] ?? null;
            preg_match_all('/:[a-z_]+/', $key, $keyPlaceholders);
            preg_match_all('/:[a-z_]+/', (string) $translation, $translationPlaceholders);

            if (! is_string($translation) || preg_match('/\p{Arabic}/u', $translation) !== 1) {
                $problems[] = basename($view).": missing Arabic for [{$key}]";
            } elseif ($keyPlaceholders[0] !== $translationPlaceholders[0]) {
                $problems[] = basename($view).": placeholder mismatch for [{$key}]";
            }
        }
    }

    expect($problems)->toBe([]);
});
