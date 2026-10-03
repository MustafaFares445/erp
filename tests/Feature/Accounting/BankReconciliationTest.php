<?php

declare(strict_types=1);

use App\Enums\AccountElement;
use App\Enums\DashboardRole;
use App\Enums\PaymentStatus;
use App\Models\BankStatement;
use App\Models\ChartAccount;
use App\Models\CustomerProfile;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Accounting\BankReconciliation\BankReconciliationService;
use App\Services\Accounting\BankReconciliation\BankReconciliationSuggestionService;
use App\Services\Accounting\BankReconciliation\BankStatementImportService;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new CurrencySeeder)->run();
    (new AccountingPermissionSeeder)->run();

    $this->actor = User::factory()->create();
    $this->actor->assignRole(DashboardRole::ChiefAccountant->value);
    $this->actingAs($this->actor);

    FiscalPeriod::factory()->create();
    $this->bank = ChartAccount::factory()->ofElement(AccountElement::Asset)->create([
        'code' => '1110',
        'name' => 'Operating Bank',
        'is_active' => true,
        'is_postable' => true,
    ]);
    $this->difference = ChartAccount::factory()->ofElement(AccountElement::Expense)->create([
        'code' => '6990',
        'name' => 'Bank Differences',
        'is_active' => true,
        'is_postable' => true,
    ]);
    $this->method = PaymentMethod::factory()->create([
        'chart_account_id' => $this->bank->id,
        'is_active' => true,
    ]);

    $this->imports = app(BankStatementImportService::class);
    $this->reconciliation = app(BankReconciliationService::class);
});

function importReconStatement(
    User $actor,
    PaymentMethod $method,
    BankStatementImportService $imports,
    array $rows,
    string $openingBalance,
    string $closingBalance,
): BankStatement {
    return $imports->import($actor, [
        'payment_method_id' => $method->id,
        'currency_code' => 'AED',
        'period_start' => today()->subDays(5)->toDateString(),
        'period_end' => today()->addDays(5)->toDateString(),
        'opening_balance' => $openingBalance,
        'closing_balance' => $closingBalance,
    ], $rows);
}

it('imports a balanced statement idempotently regardless of row order and preserves duplicate-looking rows', function (): void {
    $rows = [
        ['transaction_date' => today()->toDateString(), 'amount' => '10.00', 'reference' => 'DUP-1'],
        ['transaction_date' => today()->toDateString(), 'amount' => '10.00', 'reference' => 'DUP-1'],
        ['transaction_date' => today()->addDay()->toDateString(), 'amount' => '-5.00', 'reference' => 'FEE-1'],
    ];

    $first = importReconStatement($this->actor, $this->method, $this->imports, $rows, '100.00', '115.00');
    $second = importReconStatement($this->actor, $this->method, $this->imports, array_reverse($rows), '100.00', '115.00');

    expect($second->id)->toBe($first->id)
        ->and(BankStatement::query()->count())->toBe(1)
        ->and($first->lines()->count())->toBe(3)
        ->and($first->lines()->orderBy('sequence')->pluck('sequence')->all())->toBe([1, 2, 3]);
});

it('rejects statements whose opening balance plus movements does not equal the closing balance', function (): void {
    expect(fn (): BankStatement => importReconStatement(
        $this->actor,
        $this->method,
        $this->imports,
        [['transaction_date' => today()->toDateString(), 'amount' => '25.00']],
        '100.00',
        '999.00',
    ))->toThrow(DomainException::class, 'opening balance plus transactions');
});

function reconPayment(CustomerProfile $customer, PaymentMethod $method, string $amount, string $reference): Payment
{
    $next = Payment::query()->count() + 1;

    return Payment::query()->create([
        'payment_number' => sprintf('PAY-RECON-%04d', $next),
        'customer_id' => $customer->id,
        'payment_method_id' => $method->id,
        'amount' => $amount,
        'currency' => 'AED',
        'source' => 'manual',
        'payment_date' => today()->toDateString(),
        'external_reference' => $reference,
        'status' => PaymentStatus::Posted->value,
        'posted_at' => now(),
    ]);
}

it('ranks an exact payment reference amount and date as the best reconciliation suggestion', function (): void {
    $customer = CustomerProfile::factory()->create();
    $payment = reconPayment($customer, $this->method, '100.00', 'BANK-ABC-100');
    $statement = importReconStatement($this->actor, $this->method, $this->imports, [[
        'transaction_date' => today()->toDateString(),
        'amount' => '100.00',
        'reference' => 'BANK-ABC-100',
        'counterparty' => 'Customer transfer',
    ]], '0.00', '100.00');

    $suggestions = app(BankReconciliationSuggestionService::class)->suggest($statement->lines()->firstOrFail());

    expect($suggestions)->not->toBeEmpty()
        ->and($suggestions[0]['target_type'])->toBe(Payment::class)
        ->and($suggestions[0]['target_id'])->toBe($payment->id)
        ->and($suggestions[0]['amount'])->toBe('100.00')
        ->and($suggestions[0]['score'])->toBe(100);
});

it('supports split matching and closes only after every statement line is fully reconciled', function (): void {
    $customer = CustomerProfile::factory()->create();
    $firstPayment = reconPayment($customer, $this->method, '60.00', 'SPLIT-60');
    $secondPayment = reconPayment($customer, $this->method, '40.00', 'SPLIT-40');
    $statement = importReconStatement($this->actor, $this->method, $this->imports, [[
        'transaction_date' => today()->toDateString(),
        'amount' => '100.00',
        'reference' => 'SPLIT',
    ]], '0.00', '100.00');
    $line = $statement->lines()->firstOrFail();

    $this->reconciliation->match($this->actor, $line, $firstPayment, '60.00');
    expect($line->refresh()->status)->toBe('partial')
        ->and($line->remainingMinor())->toBe(4000);

    $this->reconciliation->match($this->actor, $line, $secondPayment, '40.00');
    $closed = $this->reconciliation->close($this->actor, $statement);

    expect($line->refresh()->status)->toBe('matched')
        ->and($line->remainingMinor())->toBe(0)
        ->and($closed->status)->toBe('reconciled')
        ->and($closed->reconciled_at)->not->toBeNull();
});

it('rejects overmatching a reconciliation target and refuses closing an unreconciled statement', function (): void {
    $customer = CustomerProfile::factory()->create();
    $payment = reconPayment($customer, $this->method, '50.00', 'OVERMATCH-50');
    $statement = importReconStatement($this->actor, $this->method, $this->imports, [[
        'transaction_date' => today()->toDateString(),
        'amount' => '100.00',
    ]], '0.00', '100.00');
    $line = $statement->lines()->firstOrFail();

    expect(fn () => $this->reconciliation->match($this->actor, $line, $payment, '60.00'))
        ->toThrow(DomainException::class, 'remaining amount available')
        ->and(fn () => $this->reconciliation->close($this->actor, $statement))
        ->toThrow(DomainException::class, 'fully reconciled');
});

it('posts a reconciliation difference only through the journal posting service and matches it back to the line', function (): void {
    $statement = importReconStatement($this->actor, $this->method, $this->imports, [[
        'transaction_date' => today()->toDateString(),
        'amount' => '2.50',
        'reference' => 'BANK-FEE-ROUNDING',
    ]], '0.00', '2.50');
    $line = $statement->lines()->firstOrFail();

    $entry = $this->reconciliation->postDifference(
        $this->actor,
        $line,
        $this->difference->id,
        'Bank reconciliation difference',
    );

    expect($entry)->toBeInstanceOf(JournalEntry::class)
        ->and($entry->status->value)->toBe('posted')
        ->and($entry->source_type)->toBe($line->getMorphClass())
        ->and($entry->source_id)->toBe($line->id)
        ->and((float) $entry->lines()->where('chart_account_id', $this->bank->id)->sum('debit'))->toBe(2.5)
        ->and($line->refresh()->status)->toBe('matched')
        ->and($line->remainingMinor())->toBe(0)
        ->and($line->matches()->where('matchable_type', JournalEntry::class)->where('matchable_id', $entry->id)->exists())->toBeTrue();
});
