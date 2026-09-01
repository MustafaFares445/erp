<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\AccountingPermission;
use App\Enums\JournalEntryStatus;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AccountingLedgerTrend extends ChartWidget
{
    protected ?string $heading = 'Posted journal activity, last 6 months';

    #[\Override]
    public static function canView(): bool
    {
        $user = auth()->user();
        if ($user?->can(AccountingPermission::JournalEntryView->value) ?? false) {
            return true;
        }
        if ($user?->can(AccountingPermission::ReceivableView->value) ?? false) {
            return true;
        }

        return (bool) ($user?->can(AccountingPermission::PayableView->value) ?? false);
    }

    /**
     * Posted debit activity per month, trailing six months inclusive of the
     * current one.
     *
     * `JournalEntry` carries no entry-level amount and no `posted_at` column
     * (see database/migrations/2026_08_18_180335_create_journal_entries_table.php),
     * so the total is built by joining `journal_entry_lines` and grouping in
     * PHP with Carbon — SQLite (the test driver) has no `DATE_FORMAT`.
     * Grouping by `entry_date` reflects the entry's business date; only
     * `posted` entries are included, so draft activity never appears.
     */
    #[\Override]
    protected function getData(): array
    {
        $start = now()->subMonths(5)->startOfMonth();

        $totals = collect(range(5, 0))
            ->mapWithKeys(fn (int $offset): array => [now()->subMonths($offset)->format('Y-m') => 0.0]);

        $rows = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->where('journal_entries.entry_date', '>=', $start->toDateString())
            ->get(['journal_entries.entry_date as entry_date', 'journal_entry_lines.debit as debit']);

        foreach ($rows as $row) {
            if (! is_string($row->entry_date) || ! is_numeric($row->debit)) {
                throw new \LogicException('Journal entry line rows must carry a date and a numeric debit.');
            }

            $month = Carbon::parse($row->entry_date)->format('Y-m');

            if ($totals->has($month)) {
                $totals[$month] += (float) $row->debit;
            }
        }

        return [
            'datasets' => [[
                'label' => 'Posted journal activity',
                'data' => $totals->values()->all(),
            ]],
            'labels' => collect(range(5, 0))
                ->map(fn (int $offset): string => now()->subMonths($offset)->format('M Y'))
                ->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
