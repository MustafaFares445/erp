<?php

declare(strict_types=1);

namespace App\Filament\Resources\BankStatements\RelationManagers;

use App\Enums\AccountingPermission;
use App\Enums\JournalEntryStatus;
use App\Enums\PaymentStatus;
use App\Enums\SupplierPaymentStatus;
use App\Filament\Concerns\InteractsWithAccountingServices;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\Accounting\BankReconciliation\BankReconciliationService;
use App\Services\Accounting\BankReconciliation\BankReconciliationSuggestionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use LogicException;

final class LinesRelationManager extends RelationManager
{
    use InteractsWithAccountingServices;

    protected static string $relationship = 'lines';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('transaction_date')
            ->columns([
                TextColumn::make('transaction_date')->date()->sortable(),
                TextColumn::make('reference')->searchable()->placeholder('—'),
                TextColumn::make('counterparty')->searchable()->placeholder('—'),
                TextColumn::make('description')->wrap()->limit(80)->placeholder('—'),
                TextColumn::make('amount')->money(fn (): string => $this->statement()->currency_code)->sortable(),
                TextColumn::make('matched_amount')
                    ->label(__('Matched'))
                    ->state(fn (BankStatementLine $record): string => number_format($record->matchedMinor() / 100, 2, '.', ''))
                    ->money(fn (): string => $this->statement()->currency_code),
                TextColumn::make('remaining_amount')
                    ->label(__('Remaining'))
                    ->state(fn (BankStatementLine $record): string => number_format($record->remainingMinor() / 100, 2, '.', ''))
                    ->money(fn (): string => $this->statement()->currency_code),
                TextColumn::make('status')->badge()->sortable(),
            ])
            ->recordActions([
                $this->suggestionAction(),
                $this->matchCustomerPaymentAction(),
                $this->matchSupplierPaymentAction(),
                $this->matchJournalEntryAction(),
                $this->differenceAction(),
            ]);
    }

    private function suggestionAction(): Action
    {
        return Action::make('suggestion')
            ->label(__('Suggestions'))
            ->icon('heroicon-o-sparkles')
            ->visible(fn (BankStatementLine $record): bool => $this->canReconcile($record))
            ->schema([
                Select::make('suggestion')
                    ->label(__('Suggested match'))
                    ->required()
                    ->searchable()
                    ->options(fn (BankStatementLine $record): array => $this->suggestionOptions($record)),
            ])
            ->action(function (BankStatementLine $record, array $data): void {
                $encoded = is_string($data['suggestion'] ?? null) ? $data['suggestion'] : '';
                $decoded = json_decode((string) base64_decode($encoded, true), true);
                if (! is_array($decoded)
                    || ! is_string($decoded['type'] ?? null)
                    || ! is_numeric($decoded['id'] ?? null)
                    || ! is_numeric($decoded['amount'] ?? null)) {
                    throw new LogicException('Invalid bank reconciliation suggestion payload.');
                }

                $targetClass = Relation::getMorphedModel($decoded['type']) ?? $decoded['type'];
                if (! in_array($targetClass, [Payment::class, SupplierPayment::class, JournalEntry::class], true)) {
                    throw new LogicException('Unsupported bank reconciliation suggestion target.');
                }

                /** @var Model $target */
                $target = $targetClass::query()->findOrFail((int) $decoded['id']);
                $this->match($record, $target, (string) $decoded['amount'], 'Accepted suggestion');
            });
    }

    private function matchCustomerPaymentAction(): Action
    {
        return Action::make('matchCustomerPayment')
            ->label(__('Match customer payment'))
            ->icon('heroicon-o-banknotes')
            ->visible(fn (BankStatementLine $record): bool => $record->amountMinor() > 0 && $this->canReconcile($record))
            ->schema([
                Select::make('payment_id')
                    ->required()
                    ->searchable()
                    ->options(fn (): array => $this->customerPaymentOptions()),
                TextInput::make('amount')->numeric()->minValue(0.01)->required(),
                Textarea::make('notes')->rows(2),
            ])
            ->action(function (BankStatementLine $record, array $data): void {
                if (! is_numeric($data['payment_id'] ?? null)) {
                    return;
                }

                $target = Payment::query()->findOrFail((int) $data['payment_id']);
                $this->match(
                    $record,
                    $target,
                    self::stringFrom($data['amount'] ?? null),
                    self::nullableStringFrom($data['notes'] ?? null),
                );
            });
    }

    private function matchSupplierPaymentAction(): Action
    {
        return Action::make('matchSupplierPayment')
            ->label(__('Match supplier payment'))
            ->icon('heroicon-o-arrow-up-tray')
            ->visible(fn (BankStatementLine $record): bool => $record->amountMinor() < 0 && $this->canReconcile($record))
            ->schema([
                Select::make('supplier_payment_id')
                    ->required()
                    ->searchable()
                    ->options(fn (): array => $this->supplierPaymentOptions()),
                TextInput::make('amount')->numeric()->minValue(0.01)->required(),
                Textarea::make('notes')->rows(2),
            ])
            ->action(function (BankStatementLine $record, array $data): void {
                if (! is_numeric($data['supplier_payment_id'] ?? null)) {
                    return;
                }

                $target = SupplierPayment::query()->findOrFail((int) $data['supplier_payment_id']);
                $this->match(
                    $record,
                    $target,
                    self::stringFrom($data['amount'] ?? null),
                    self::nullableStringFrom($data['notes'] ?? null),
                );
            });
    }

    private function matchJournalEntryAction(): Action
    {
        return Action::make('matchJournalEntry')
            ->label(__('Match journal entry'))
            ->icon('heroicon-o-book-open')
            ->visible(fn (BankStatementLine $record): bool => $this->canReconcile($record))
            ->schema([
                Select::make('journal_entry_id')
                    ->required()
                    ->searchable()
                    ->options(fn (): array => $this->journalEntryOptions()),
                TextInput::make('amount')->numeric()->minValue(0.01)->required(),
                Textarea::make('notes')->rows(2),
            ])
            ->action(function (BankStatementLine $record, array $data): void {
                if (! is_numeric($data['journal_entry_id'] ?? null)) {
                    return;
                }

                $target = JournalEntry::query()->findOrFail((int) $data['journal_entry_id']);
                $this->match(
                    $record,
                    $target,
                    self::stringFrom($data['amount'] ?? null),
                    self::nullableStringFrom($data['notes'] ?? null),
                );
            });
    }

    private function differenceAction(): Action
    {
        return Action::make('difference')
            ->label(__('Post difference'))
            ->icon('heroicon-o-scale')
            ->color('warning')
            ->visible(fn (BankStatementLine $record): bool => $this->canReconcile($record))
            ->schema([
                Select::make('difference_account_id')
                    ->label(__('Difference account'))
                    ->required()
                    ->searchable()
                    ->options(fn (): array => $this->differenceAccountOptions()),
                Textarea::make('description')->rows(2),
            ])
            ->requiresConfirmation()
            ->action(function (BankStatementLine $record, array $data): void {
                $actor = self::accountingActor();
                if (! $actor instanceof User || ! is_numeric($data['difference_account_id'] ?? null)) {
                    return;
                }

                self::runAccountingOperation(fn (): JournalEntry => app(BankReconciliationService::class)->postDifference(
                    $actor,
                    $record,
                    (int) $data['difference_account_id'],
                    self::nullableStringFrom($data['description'] ?? null),
                ));
            });
    }

    private function match(BankStatementLine $line, Model $target, string $amount, ?string $notes): void
    {
        $actor = self::accountingActor();
        if (! $actor instanceof User) {
            return;
        }

        self::runAccountingOperation(fn () => app(BankReconciliationService::class)->match(
            $actor,
            $line,
            $target,
            $amount,
            $notes,
        ));
    }

    /** @return array<string, string> */
    private function suggestionOptions(BankStatementLine $line): array
    {
        $options = [];

        foreach (app(BankReconciliationSuggestionService::class)->suggest($line) as $suggestion) {
            $payload = base64_encode(json_encode([
                'type' => $suggestion['target_type'],
                'id' => $suggestion['target_id'],
                'amount' => $suggestion['amount'],
            ], JSON_THROW_ON_ERROR));
            $reasons = $suggestion['reasons'] === [] ? '' : ' · '.implode(', ', $suggestion['reasons']);
            $options[$payload] = $suggestion['label'].' · '.$suggestion['amount'].' · '.$suggestion['date'].' · '.$suggestion['score'].'%'.$reasons;
        }

        return $options;
    }

    /** @return array<int, string> */
    private function customerPaymentOptions(): array
    {
        $statement = $this->statement();

        return Payment::query()
            ->with('customer:id,company_name')
            ->where('payment_method_id', $statement->payment_method_id)
            ->where('status', PaymentStatus::Posted->value)
            ->whereNull('reversed_at')
            ->where('currency', $statement->currency_code)
            ->latest('payment_date')
            ->limit(100)
            ->get()
            ->mapWithKeys(function (Payment $payment): array {
                $customerName = data_get($payment, 'customer.company_name');

                return [
                    $payment->id => $payment->payment_number.' · '.$payment->amount.' · '.$payment->payment_date->toDateString().' · '.(is_string($customerName) ? $customerName : '—'),
                ];
            })
            ->all();
    }

    /** @return array<int, string> */
    private function supplierPaymentOptions(): array
    {
        $statement = $this->statement();

        return SupplierPayment::query()
            ->with('supplier:id,name')
            ->where('payment_method_id', $statement->payment_method_id)
            ->where('status', SupplierPaymentStatus::Paid->value)
            ->latest('payment_date')
            ->limit(100)
            ->get()
            ->mapWithKeys(function (SupplierPayment $payment): array {
                $supplierName = data_get($payment, 'supplier.name');

                return [
                    $payment->id => $payment->supplier_payment_number.' · '.$payment->amount.' · '.$payment->payment_date->toDateString().' · '.(is_string($supplierName) ? $supplierName : '—'),
                ];
            })
            ->all();
    }

    /** @return array<int, string> */
    private function journalEntryOptions(): array
    {
        $bankAccount = $this->statement()->paymentMethod?->chartAccount;
        if (! $bankAccount instanceof ChartAccount) {
            return [];
        }

        return JournalEntry::query()
            ->where('status', JournalEntryStatus::Posted->value)
            ->whereHas('lines', fn ($query) => $query->where('chart_account_id', $bankAccount->id))
            ->latest('entry_date')
            ->limit(100)
            ->get()
            ->mapWithKeys(fn (JournalEntry $entry): array => [
                $entry->id => $entry->entry_number.' · '.$entry->entry_date->toDateString().' · '.($entry->description ?? '—'),
            ])
            ->all();
    }

    /** @return array<int, string> */
    private function differenceAccountOptions(): array
    {
        $bankAccountId = $this->statement()->paymentMethod?->chart_account_id;

        return ChartAccount::query()
            ->where('is_active', true)
            ->where('is_postable', true)
            ->when(is_numeric($bankAccountId), fn ($query) => $query->whereKeyNot((int) $bankAccountId))
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (ChartAccount $account): array => [$account->id => $account->code.' · '.$account->name])
            ->all();
    }

    private function canReconcile(BankStatementLine $line): bool
    {
        return $this->statement()->status === 'open'
            && $line->remainingMinor() > 0
            && (auth()->user()?->can(AccountingPermission::BankReconciliationManage->value) ?? false);
    }

    private function statement(): BankStatement
    {
        $record = $this->getOwnerRecord();
        if (! $record instanceof BankStatement) {
            throw new LogicException('Expected a BankStatement owner record.');
        }

        return $record;
    }
}
