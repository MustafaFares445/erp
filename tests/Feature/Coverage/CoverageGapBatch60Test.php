<?php

declare(strict_types=1);

use App\Enums\AccountElement;
use App\Enums\DashboardRole;
use App\Enums\JournalEntryStatus;
use App\Enums\PaymentStatus;
use App\Enums\SupplierPaymentStatus;
use App\Filament\Resources\BankStatements\Pages\ViewBankStatement;
use App\Filament\Resources\BankStatements\RelationManagers\LinesRelationManager;
use App\Models\BankStatement;
use App\Models\ChartAccount;
use App\Models\CustomerProfile;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\Accounting\BankReconciliation\BankStatementImportService;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function coverage60Fixture(): array
{
    (new CurrencySeeder)->run();
    (new AccountingPermissionSeeder)->run();

    $actor = User::factory()->create();
    $actor->assignRole(DashboardRole::ChiefAccountant->value);

    $period = FiscalPeriod::factory()->create();
    $bank = ChartAccount::factory()->ofElement(AccountElement::Asset)->create([
        'code' => '1110-C60',
        'name' => 'Coverage bank',
        'is_active' => true,
        'is_postable' => true,
    ]);
    $difference = ChartAccount::factory()->ofElement(AccountElement::Expense)->create([
        'code' => '6990-C60',
        'name' => 'Coverage difference',
        'is_active' => true,
        'is_postable' => true,
    ]);
    $method = PaymentMethod::factory()->create([
        'chart_account_id' => $bank->id,
        'is_active' => true,
    ]);

    $rows = [
        ['transaction_date' => today()->toDateString(), 'amount' => '100.00', 'reference' => 'C60-CUSTOMER'],
        ['transaction_date' => today()->toDateString(), 'amount' => '-50.00', 'reference' => 'C60-SUPPLIER'],
        ['transaction_date' => today()->toDateString(), 'amount' => '25.00', 'reference' => 'C60-JOURNAL'],
        ['transaction_date' => today()->toDateString(), 'amount' => '2.50', 'reference' => 'C60-DIFF'],
        ['transaction_date' => today()->toDateString(), 'amount' => '10.00', 'reference' => 'C60-SUGGEST'],
    ];

    $statement = app(BankStatementImportService::class)->import($actor, [
        'payment_method_id' => $method->id,
        'currency_code' => 'AED',
        'period_start' => today()->subDay()->toDateString(),
        'period_end' => today()->addDay()->toDateString(),
        'opening_balance' => '0.00',
        'closing_balance' => '87.50',
    ], $rows);

    $customer = CustomerProfile::factory()->create(['company_name' => 'Coverage customer']);

    $customerPayment = Payment::query()->create([
        'payment_number' => 'PAY-C60-001',
        'customer_id' => $customer->id,
        'payment_method_id' => $method->id,
        'amount' => '100.00',
        'currency' => 'AED',
        'source' => 'manual',
        'payment_date' => today()->toDateString(),
        'external_reference' => 'C60-CUSTOMER',
        'status' => PaymentStatus::Posted->value,
        'posted_at' => now(),
    ]);

    $suggestedPayment = Payment::query()->create([
        'payment_number' => 'PAY-C60-002',
        'customer_id' => $customer->id,
        'payment_method_id' => $method->id,
        'amount' => '10.00',
        'currency' => 'AED',
        'source' => 'manual',
        'payment_date' => today()->toDateString(),
        'external_reference' => 'C60-SUGGEST',
        'status' => PaymentStatus::Posted->value,
        'posted_at' => now(),
    ]);

    $supplierPayment = SupplierPayment::factory()->create([
        'payment_method_id' => $method->id,
        'amount' => '50.00',
        'payment_date' => today(),
        'status' => SupplierPaymentStatus::Paid->value,
    ]);

    $entry = JournalEntry::factory()->create([
        'entry_date' => today(),
        'description' => 'Coverage journal match',
    ]);
    JournalEntryLine::factory()->for($entry)->debit('25.00')->create([
        'chart_account_id' => $bank->id,
        'sort_order' => 1,
    ]);
    JournalEntryLine::factory()->for($entry)->credit('25.00')->create([
        'chart_account_id' => $difference->id,
        'sort_order' => 2,
    ]);
    $entry->forceFill([
        'status' => JournalEntryStatus::Posted->value,
        'fiscal_period_id' => $period->id,
    ])->saveQuietly();

    return compact(
        'actor',
        'bank',
        'difference',
        'method',
        'statement',
        'customerPayment',
        'suggestedPayment',
        'supplierPayment',
        'entry',
    );
}

function coverage60Manager(BankStatement $statement): LinesRelationManager
{
    $manager = new LinesRelationManager;
    $manager->ownerRecord = $statement;

    return $manager;
}

it('covers bank-statement reconciliation row actions end to end', function (): void {
    $fixture = coverage60Fixture();
    $statement = $fixture['statement'];
    $actor = $fixture['actor'];

    $lines = $statement->lines()->orderBy('sequence')->get();
    [$customerLine, $supplierLine, $journalLine, $differenceLine, $suggestionLine] = $lines->all();

    Livewire::actingAs($actor)
        ->test(LinesRelationManager::class, [
            'ownerRecord' => $statement,
            'pageClass' => ViewBankStatement::class,
        ])
        ->callTableAction('matchCustomerPayment', $customerLine, data: [
            'payment_id' => $fixture['customerPayment']->id,
            'amount' => '100.00',
            'notes' => 'Customer coverage match',
        ])
        ->assertHasNoActionErrors();

    Livewire::actingAs($actor)
        ->test(LinesRelationManager::class, [
            'ownerRecord' => $statement->refresh(),
            'pageClass' => ViewBankStatement::class,
        ])
        ->callTableAction('matchSupplierPayment', $supplierLine, data: [
            'supplier_payment_id' => $fixture['supplierPayment']->id,
            'amount' => '50.00',
            'notes' => 'Supplier coverage match',
        ])
        ->assertHasNoActionErrors();

    Livewire::actingAs($actor)
        ->test(LinesRelationManager::class, [
            'ownerRecord' => $statement->refresh(),
            'pageClass' => ViewBankStatement::class,
        ])
        ->callTableAction('matchJournalEntry', $journalLine, data: [
            'journal_entry_id' => $fixture['entry']->id,
            'amount' => '25.00',
            'notes' => 'Journal coverage match',
        ])
        ->assertHasNoActionErrors();

    Livewire::actingAs($actor)
        ->test(LinesRelationManager::class, [
            'ownerRecord' => $statement->refresh(),
            'pageClass' => ViewBankStatement::class,
        ])
        ->callTableAction('difference', $differenceLine, data: [
            'difference_account_id' => $fixture['difference']->id,
            'description' => 'Coverage difference',
        ])
        ->assertHasNoActionErrors();

    $payload = base64_encode(json_encode([
        'type' => Payment::class,
        'id' => $fixture['suggestedPayment']->id,
        'amount' => '10.00',
    ], JSON_THROW_ON_ERROR));

    Livewire::actingAs($actor)
        ->test(LinesRelationManager::class, [
            'ownerRecord' => $statement->refresh(),
            'pageClass' => ViewBankStatement::class,
        ])
        ->callTableAction('suggestion', $suggestionLine, data: [
            'suggestion' => $payload,
        ])
        ->assertHasNoActionErrors();

    foreach ($lines as $line) {
        expect($line->refresh()->remainingMinor())->toBe(0);
    }
});

it('covers bank-statement option builders and suggestion payload rendering', function (): void {
    $fixture = coverage60Fixture();
    $this->actingAs($fixture['actor']);

    $manager = coverage60Manager($fixture['statement']);
    $lines = $fixture['statement']->lines()->orderBy('sequence')->get();

    $suggestionOptions = new ReflectionMethod(LinesRelationManager::class, 'suggestionOptions')
        ->invoke($manager, $lines->last());
    $customerOptions = new ReflectionMethod(LinesRelationManager::class, 'customerPaymentOptions')
        ->invoke($manager);
    $supplierOptions = new ReflectionMethod(LinesRelationManager::class, 'supplierPaymentOptions')
        ->invoke($manager);
    $journalOptions = new ReflectionMethod(LinesRelationManager::class, 'journalEntryOptions')
        ->invoke($manager);
    $differenceOptions = new ReflectionMethod(LinesRelationManager::class, 'differenceAccountOptions')
        ->invoke($manager);

    expect($suggestionOptions)->not->toBeEmpty()
        ->and($customerOptions)->toHaveKey($fixture['customerPayment']->id)
        ->and($supplierOptions)->toHaveKey($fixture['supplierPayment']->id)
        ->and($journalOptions)->toHaveKey($fixture['entry']->id)
        ->and($differenceOptions)->toHaveKey($fixture['difference']->id)
        ->and($differenceOptions)->not->toHaveKey($fixture['bank']->id);
});

it('covers unsupported suggestion targets and defensive owner or actor branches', function (): void {
    $fixture = coverage60Fixture();
    $statement = $fixture['statement'];
    $line = $statement->lines()->firstOrFail();

    $unsupported = base64_encode(json_encode([
        'type' => User::class,
        'id' => $fixture['actor']->id,
        'amount' => '1.00',
    ], JSON_THROW_ON_ERROR));

    $this->actingAs($fixture['actor']);
    $manager = coverage60Manager($statement);
    $action = new ReflectionMethod(LinesRelationManager::class, 'suggestionAction')->invoke($manager);
    $actionFunction = $action->getActionFunction();
    expect($actionFunction)->not->toBeNull()
        ->and(fn () => $actionFunction($line, ['suggestion' => $unsupported]))
        ->toThrow(LogicException::class, 'Unsupported bank reconciliation suggestion target');

    auth()->logout();
    $manager = coverage60Manager($statement);
    new ReflectionMethod(LinesRelationManager::class, 'match')
        ->invoke($manager, $line, $fixture['customerPayment'], '1.00', null);

    $invalid = new LinesRelationManager;
    $invalid->ownerRecord = $fixture['customerPayment'];

    expect(fn () => new ReflectionMethod(LinesRelationManager::class, 'statement')->invoke($invalid))
        ->toThrow(LogicException::class, 'Expected a BankStatement owner record');
});
