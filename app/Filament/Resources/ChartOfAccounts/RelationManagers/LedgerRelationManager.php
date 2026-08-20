<?php

declare(strict_types=1);

namespace App\Filament\Resources\ChartOfAccounts\RelationManagers;

use App\Enums\JournalEntryStatus;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\Accounting\AccountBalanceService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * One account's posted lines with a running balance (FR-038).
 *
 * Read-only by construction: there is no header, record, or toolbar action,
 * because a ledger line only ever comes into existence as part of posting an
 * entry. Drafts are excluded — they are not in the ledger, and including them
 * would make the running balance disagree with the account's reported balance.
 *
 * @see /specs/018-chart-of-accounts-journals/spec.md User Story 6
 */
final class LedgerRelationManager extends RelationManager
{
    protected static string $relationship = 'journalEntryLines';

    protected static ?string $title = 'Ledger';

    /** @var array<int, string>|null */
    private ?array $runningBalances = null;

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->whereHas('journalEntry', fn (Builder $entries): Builder => $entries
                    ->where('status', JournalEntryStatus::Posted->value))
                ->with('journalEntry')
                // The same order AccountBalanceService::ledgerFor() uses, as a
                // correlated subquery rather than a join so the primary key stays
                // unambiguous for Filament's record identification. Display order
                // and running-balance order must agree or the column reads as
                // non-monotonic nonsense.
                ->orderBy(JournalEntry::query()
                    ->select('entry_date')
                    ->whereColumn('journal_entries.id', 'journal_entry_lines.journal_entry_id'))
                ->orderBy('journal_entry_id')
                ->orderBy('sort_order'))
            ->columns([
                TextColumn::make('journalEntry.entry_number')
                    ->label(__('admin.accounting.fields.entry_number')),
                TextColumn::make('journalEntry.entry_date')
                    ->label(__('admin.accounting.fields.entry_date'))
                    ->date(),
                TextColumn::make('description')
                    ->label(__('admin.accounting.fields.description'))
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('debit')
                    ->label(__('admin.accounting.fields.debit'))
                    ->alignEnd(),
                TextColumn::make('credit')
                    ->label(__('admin.accounting.fields.credit'))
                    ->alignEnd(),
                TextColumn::make('running_balance')
                    ->label(__('admin.accounting.fields.running_balance'))
                    ->state(fn (JournalEntryLine $record): string => $this->runningBalanceOf($record))
                    ->alignEnd(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }

    /**
     * Accumulated over the account's whole ledger, not just the visible page, so
     * page two continues page one's total instead of restarting at zero.
     */
    private function runningBalanceOf(JournalEntryLine $line): string
    {
        if ($this->runningBalances === null) {
            $balances = app(AccountBalanceService::class);
            $account = $this->account();

            $this->runningBalances = $balances->runningBalances($account, $balances->ledgerFor($account));
        }

        return $this->runningBalances[$line->id] ?? '0.00';
    }

    private function account(): ChartAccount
    {
        $record = $this->getOwnerRecord();

        if (! $record instanceof ChartAccount) {
            throw new LogicException('Expected the owner record of LedgerRelationManager to be a ChartAccount.');
        }

        return $record;
    }
}
