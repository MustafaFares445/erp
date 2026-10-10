<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Filament\Resources\BankStatements\BankStatementResource;
use App\Filament\Resources\BankStatements\Pages\ListBankStatements;
use App\Filament\Resources\BankStatements\Pages\ViewBankStatement;
use App\Filament\Resources\BankStatements\RelationManagers\LinesRelationManager;
use App\Models\User;
use App\Services\Accounting\BankReconciliation\BankStatementImportService;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders translated statement and line statuses with isolated numeric progress', function (string $locale, string $openLabel, string $unmatchedLabel, string $linesLabel): void {
    (new CurrencySeeder)->run();
    (new AccountingPermissionSeeder)->run();
    $method = configurePaymentAccounting();
    $admin = User::factory()->admin()->create(['locale' => $locale]);
    $admin->assignRole(DashboardRole::ChiefAccountant->value);

    $statement = app(BankStatementImportService::class)->import($admin, [
        'payment_method_id' => $method->getKey(),
        'currency_code' => 'AED',
        'period_start' => today()->toDateString(),
        'period_end' => today()->toDateString(),
        'opening_balance' => '0.00',
        'closing_balance' => '30.00',
    ], [
        ['transaction_date' => today()->toDateString(), 'amount' => '10.00', 'reference' => 'QA-FIRST'],
        ['transaction_date' => today()->toDateString(), 'amount' => '20.00', 'reference' => 'QA-SECOND'],
    ]);
    app()->setLocale($locale);

    Livewire::actingAs($admin)
        ->test(ListBankStatements::class)
        ->assertSeeText($openLabel)
        ->assertSee('<bdi dir="ltr">0 / 2</bdi>', false);

    Livewire::actingAs($admin)
        ->test(ViewBankStatement::class, ['record' => $statement->getKey()])
        ->assertSeeText($openLabel)
        ->assertSeeText(__('Imported at'))
        ->assertSeeText(__('Reconciled at'))
        ->assertSee('<bdi dir="ltr">0 / 2</bdi>', false);

    Livewire::actingAs($admin)
        ->test(LinesRelationManager::class, ['ownerRecord' => $statement, 'pageClass' => ViewBankStatement::class])
        ->assertSeeText($linesLabel)
        ->assertSeeText($unmatchedLabel)
        ->assertDontSeeText('unmatched');
})->with([
    'English' => ['en', 'Open', 'Unmatched', 'Statement transactions'],
    'Arabic' => ['ar', 'مفتوح', 'غير مطابق', 'حركات الكشف'],
]);

it('renders the bank statement model labels in Arabic without changing the English labels', function (): void {
    app()->setLocale('ar');

    expect(BankStatementResource::getModelLabel())->toBe('كشف حساب بنكي')
        ->and(BankStatementResource::getPluralModelLabel())->toBe('كشوف الحسابات البنكية');

    app()->setLocale('en');

    expect(BankStatementResource::getModelLabel())->toBe('Bank statement')
        ->and(BankStatementResource::getPluralModelLabel())->toBe('Bank statements');
});

it('translates all bank reconciliation workflow headings used by the user guide', function (): void {
    app()->setLocale('ar');

    foreach ([
        'Bank reconciliation',
        'Bank / method',
        'Statement',
        'Import statement',
        'Statement details',
        'Statement transactions',
        'Reconciliation progress',
        'Close reconciliation',
        'Period start',
        'Period end',
        'Imported at',
        'Reconciled at',
        'Suggestions',
    ] as $label) {
        expect(__($label))->not->toBe($label);
    }
});
