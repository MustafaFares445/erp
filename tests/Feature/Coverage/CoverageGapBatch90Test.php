<?php

declare(strict_types=1);

use App\Enums\AccountElement;
use App\Enums\JournalEntryStatus;
use App\Enums\PaymentStatus;
use App\Enums\SupplierPaymentStatus;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\ChartAccount;
use App\Models\CustomerProfile;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\Accounting\BankReconciliation\BankReconciliationService;
use App\Services\Accounting\BankReconciliation\BankReconciliationSuggestionService;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
    (new CurrencySeeder)->run();
    FiscalPeriod::factory()->create();
});

function coverage90Setup(): array
{
    $actor = User::factory()->create();
    $bank = ChartAccount::factory()->ofElement(AccountElement::Asset)->create([
        'is_active' => true,
        'is_postable' => true,
    ]);
    $difference = ChartAccount::factory()->ofElement(AccountElement::Expense)->create([
        'is_active' => true,
        'is_postable' => true,
    ]);
    $method = PaymentMethod::factory()->create([
        'chart_account_id' => $bank->id,
        'is_active' => true,
    ]);

    return [$actor, $bank, $difference, $method];
}

function coverage90Statement(PaymentMethod $method, string $amount = '100.00', string $reference = 'REF-90'): array
{
    $statement = new BankStatement([
        'payment_method_id' => $method->id,
        'currency_code' => 'AED',
        'period_start' => today()->subDays(10)->toDateString(),
        'period_end' => today()->addDays(10)->toDateString(),
        'opening_balance' => '0.00',
        'closing_balance' => $amount,
    ]);
    $statement->forceFill([
        'statement_number' => 'BST-COV90-'.uniqid(),
        'import_hash' => hash('sha256', uniqid('cov90', true)),
        'status' => 'open',
        'imported_at' => now(),
    ])->save();

    $line = $statement->lines()->create([
        'sequence' => 1,
        'line_hash' => hash('sha256', uniqid('line90', true)),
        'transaction_date' => today()->toDateString(),
        'amount' => $amount,
        'reference' => $reference,
        'status' => 'unmatched',
    ]);

    return [$statement->refresh(), $line->refresh()];
}

function coverage90Payment(PaymentMethod $method, string $amount, string $date, string $reference): Payment
{
    return Payment::query()->create([
        'payment_number' => 'PAY-COV90-'.uniqid(),
        'customer_id' => CustomerProfile::factory()->create()->id,
        'payment_method_id' => $method->id,
        'amount' => $amount,
        'currency' => 'AED',
        'source' => 'manual',
        'payment_date' => $date,
        'external_reference' => $reference,
        'status' => PaymentStatus::Posted,
        'posted_at' => now(),
    ]);
}

it('covers reconciliation suggestion early returns supplier branch and scoring tiers', function (): void {
    [$actor, $bank, $difference, $method] = coverage90Setup();
    [$statement, $line] = coverage90Statement($method);

    $suggestions = app(BankReconciliationSuggestionService::class);
    $candidate = new ReflectionMethod(BankReconciliationSuggestionService::class, 'candidate');

    $withinFive = coverage90Payment($method, '104.00', today()->addDay()->toDateString(), 'prefix-ref-90-suffix');
    $oneDay = $candidate->invoke($suggestions, $line, $withinFive, 10400, today()->addDay()->toDateString(), 'Label', 'prefix REF-90 suffix');

    expect($oneDay['reasons'])->toContain('Amount within 5%', 'Date within 1 day', 'Reference match');

    $threeDayPayment = coverage90Payment($method, '120.00', today()->addDays(3)->toDateString(), 'OTHER-3');
    $threeDay = $candidate->invoke($suggestions, $line, $threeDayPayment, 12000, today()->addDays(3)->toDateString(), 'REF-90 embedded label', null);
    expect($threeDay['reasons'])->toContain('Date within 3 days', 'Reference match');

    $fiveDayPayment = coverage90Payment($method, '150.00', today()->addDays(5)->toDateString(), 'OTHER-5');
    $fiveDay = $candidate->invoke($suggestions, $line, $fiveDayPayment, 15000, today()->addDays(5)->toDateString(), 'No reference', null);
    expect($fiveDay['reasons'])->toContain('Date within 5 days');

    expect($candidate->invoke($suggestions, $line, $fiveDayPayment, 0, today()->toDateString(), 'Zero', null))->toBeNull();

    $statement->forceFill(['status' => 'reconciled'])->save();
    expect($suggestions->suggest($line->refresh()))->toBe([]);

    [$negativeStatement, $negativeLine] = coverage90Statement($method, '-75.00', 'SUP-REF');
    $supplierPayment = SupplierPayment::factory()->create([
        'payment_method_id' => $method->id,
        'amount' => '75.00',
        'payment_date' => today(),
        'reference' => 'SUP-REF',
        'status' => SupplierPaymentStatus::Paid,
    ]);

    $negativeSuggestions = $suggestions->suggest($negativeLine);
    expect($negativeSuggestions)->not->toBeEmpty()
        ->and($negativeSuggestions[0]['target_type'])->toBe($supplierPayment->getMorphClass());

    // A preloaded missing chart-account relation produces no suggestions.
    [$unmappedStatement, $unmappedLine] = coverage90Statement($method);
    $loadedMethod = $unmappedStatement->paymentMethod;
    $loadedMethod->setRelation('chartAccount', null);
    $unmappedStatement->setRelation('paymentMethod', $loadedMethod);
    $unmappedLine->setRelation('statement', $unmappedStatement);
    expect($suggestions->suggest($unmappedLine))->toBe([]);
});

it('covers reconciliation target eligibility guards for payments supplier payments journals and unsupported targets', function (): void {
    [$actor, $bank, $difference, $method] = coverage90Setup();
    [$statement, $positiveLine] = coverage90Statement($method);
    [, $negativeLine] = coverage90Statement($method, '-100.00');

    $service = app(BankReconciliationService::class);
    $targetAmount = new ReflectionMethod(BankReconciliationService::class, 'targetAmountMinor');

    $payment = coverage90Payment($method, '100.00', today()->toDateString(), 'PAY');
    expect(fn () => $targetAmount->invoke($service, $statement, $negativeLine, $payment))
        ->toThrow(DomainException::class, 'customer payment is not eligible');

    $payment->forceFill(['status' => PaymentStatus::Draft])->save();
    expect(fn () => $targetAmount->invoke($service, $statement, $positiveLine, $payment->refresh()))
        ->toThrow(DomainException::class, 'customer payment is not eligible');

    $supplierPayment = SupplierPayment::factory()->create([
        'payment_method_id' => $method->id,
        'amount' => '100.00',
        'status' => SupplierPaymentStatus::Paid,
    ]);
    expect(fn () => $targetAmount->invoke($service, $statement, $positiveLine, $supplierPayment))
        ->toThrow(DomainException::class, 'supplier payment is not eligible');

    $draftEntry = JournalEntry::factory()->create();
    expect(fn () => $targetAmount->invoke($service, $statement, $positiveLine, $draftEntry))
        ->toThrow(DomainException::class, 'Only posted journal entries');

    $inactiveBank = ChartAccount::factory()->ofElement(AccountElement::Asset)->inactive()->create(['is_postable' => true]);
    $unmappedMethod = PaymentMethod::factory()->create(['chart_account_id' => $inactiveBank->id, 'is_active' => true]);
    [$unmappedStatement, $unmappedLine] = coverage90Statement($unmappedMethod);
    $postedEntry = JournalEntry::factory()->postedAndBalanced()->create();
    expect(fn () => $targetAmount->invoke($service, $unmappedStatement, $unmappedLine, $postedEntry))
        ->toThrow(DomainException::class, 'not mapped to an active postable bank account');

    $wrongMovement = JournalEntry::factory()->create();
    JournalEntryLine::factory()->for($wrongMovement)->credit('100.00')->create([
        'chart_account_id' => $bank->id,
    ]);
    JournalEntryLine::factory()->for($wrongMovement)->debit('100.00')->create([
        'chart_account_id' => $difference->id,
        'sort_order' => 2,
    ]);
    $wrongMovement->forceFill([
        'status' => JournalEntryStatus::Posted,
        'fiscal_period_id' => FiscalPeriod::query()->value('id'),
    ])->saveQuietly();

    expect(fn () => $targetAmount->invoke($service, $statement, $positiveLine, $wrongMovement))
        ->toThrow(DomainException::class, 'compatible movement');

    expect(fn () => $targetAmount->invoke($service, $statement, $positiveLine, ProductVariant::factory()->create()))
        ->toThrow(DomainException::class, 'Unsupported bank reconciliation match target');
});

it('covers match post-difference and close guards including the negative-statement journal branch', function (): void {
    [$actor, $bank, $difference, $method] = coverage90Setup();
    $service = app(BankReconciliationService::class);

    [$closedStatement, $closedLine] = coverage90Statement($method);
    $closedStatement->forceFill(['status' => 'reconciled'])->save();
    $payment = coverage90Payment($method, '100.00', today()->toDateString(), 'CLOSED');

    expect(fn () => $service->match($actor, $closedLine, $payment, '100.00'))
        ->toThrow(DomainException::class, 'Only an open bank statement');

    [$statement, $line] = coverage90Statement($method);
    expect(fn () => $service->match($actor, $line, $payment, '0'))
        ->toThrow(DomainException::class, 'exceeds the unreconciled');

    expect(fn () => $service->postDifference($actor, $closedLine, $difference->id))
        ->toThrow(DomainException::class, 'Only an open bank statement');

    expect(fn () => $service->postDifference($actor, $line, $bank->id))
        ->toThrow(DomainException::class, 'different from the bank account');

    $service->match($actor, $line, $payment, '100.00');
    expect(fn () => $service->postDifference($actor, $line->refresh(), $difference->id))
        ->toThrow(DomainException::class, 'already fully reconciled');

    [, $negativeLine] = coverage90Statement($method, '-2.50', 'NEG-DIFF');
    $entry = $service->postDifference($actor, $negativeLine, $difference->id, 'Negative reconciliation difference');

    expect((float) $entry->lines()->where('chart_account_id', $difference->id)->sum('debit'))->toBe(2.5)
        ->and((float) $entry->lines()->where('chart_account_id', $bank->id)->sum('credit'))->toBe(2.5)
        ->and($negativeLine->refresh()->remainingMinor())->toBe(0);

    expect(fn () => $service->close($actor, $closedStatement))
        ->toThrow(DomainException::class, 'Only an open bank statement');
});
