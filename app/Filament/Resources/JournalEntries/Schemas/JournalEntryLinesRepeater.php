<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalEntries\Schemas;

use App\Models\ChartAccount;
use App\Models\JournalEntryLine;
use App\Services\Accounting\JournalPostingService;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The lines of a draft entry, plus the running debit/credit totals the operator
 * balances against before posting.
 *
 * Deliberately permissive: a line may carry both sides or neither, and the
 * totals may disagree. Those are posting-time rejections
 * ({@see JournalPostingService}), not save-time ones —
 * a draft that cannot be saved half-finished is not a draft (research.md R-012).
 * Only postable, active accounts are offered, because those are the only ones
 * posting would accept.
 */
final class JournalEntryLinesRepeater
{
    public static function make(): Repeater
    {
        return Repeater::make('lines')
            ->relationship()
            // Restores the reordering that `relationship()` switches off, and
            // persists the order to the additive `sort_order` column (ERD E-2).
            ->orderColumn('sort_order')
            ->columns(4)
            ->schema([
                Select::make('chart_account_id')
                    ->label(__('admin.accounting.fields.account'))
                    ->options(self::postableAccountOptions(...))
                    ->searchable()
                    ->required(),
                TextInput::make('debit')
                    ->label(__('admin.accounting.fields.debit'))
                    ->numeric()
                    ->minValue(0)
                    ->step('0.01')
                    ->default(0)
                    // On blur rather than on keystroke: the totals below only
                    // become meaningful once an amount is finished being typed.
                    ->live(onBlur: true),
                TextInput::make('credit')
                    ->label(__('admin.accounting.fields.credit'))
                    ->numeric()
                    ->minValue(0)
                    ->step('0.01')
                    ->default(0)
                    ->live(onBlur: true),
                TextInput::make('description')
                    ->label(__('admin.accounting.fields.description'))
                    ->maxLength(255),
            ])
            ->defaultItems(2)
            ->columnSpanFull();
    }

    /**
     * The live debit and credit totals of whatever is currently in the repeater.
     *
     * Summed in integer minor units through {@see JournalEntryLine::toMinorUnits()}
     * so the figure the operator balances against is computed exactly the way
     * posting will compute it (FR-030) — a total that reads balanced here must
     * never be rejected as unbalanced a moment later.
     */
    public static function totals(): Placeholder
    {
        return Placeholder::make('line_totals')
            ->label(__('admin.accounting.fields.lines'))
            ->content(function (Get $get): string {
                $debitMinor = 0;
                $creditMinor = 0;
                $lines = $get('lines');

                foreach (is_array($lines) ? $lines : [] as $line) {
                    if (! is_array($line)) {
                        continue;
                    }

                    $debitMinor += JournalEntryLine::toMinorUnits($line['debit'] ?? 0);
                    $creditMinor += JournalEntryLine::toMinorUnits($line['credit'] ?? 0);
                }

                return __('admin.accounting.hints.line_totals', [
                    'debit' => self::format($debitMinor),
                    'credit' => self::format($creditMinor),
                ]);
            })
            ->columnSpanFull();
    }

    /**
     * The only accounts posting would accept — postable leaves that are still
     * active. Offering any other account would guarantee a rejection later.
     *
     * @return array<int, string>
     */
    private static function postableAccountOptions(): array
    {
        $options = [];

        $accounts = ChartAccount::query()
            ->where('is_postable', true)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        foreach ($accounts as $account) {
            $options[$account->id] = $account->code.' — '.$account->name;
        }

        return $options;
    }

    private static function format(int $minorUnits): string
    {
        return number_format($minorUnits / 100, 2, '.', '');
    }
}
