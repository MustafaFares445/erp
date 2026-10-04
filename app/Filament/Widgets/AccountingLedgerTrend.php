<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\AccountingPermission;
use App\Enums\JournalEntryStatus;
use App\Filament\Support\IerpColors;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

final class AccountingLedgerTrend extends ChartWidget
{
    protected static bool $isLazy = false;

    use InteractsWithDashboardFilters;

    protected ?string $maxHeight = '300px';

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

    #[\Override]
    public function getHeading(): string
    {
        return __('dashboards.accounting.charts.ledger');
    }

    /**
     * Posted debit activity per bucket of the selected window, with the
     * equal-length previous window for comparison.
     *
     * `JournalEntry` carries no entry-level amount and no `posted_at` column
     * (see database/migrations/2026_08_18_180335_create_journal_entries_table.php),
     * so the total is built by joining `journal_entry_lines`. Grouping by
     * `entry_date` reflects the entry's business date; only `posted` entries
     * are included, so draft activity never appears.
     */
    #[\Override]
    protected function getData(): array
    {
        $period = $this->dashboardPeriod();

        return [
            'datasets' => [
                [
                    'label' => __('dashboards.charts.selected_period'),
                    'data' => $period->sumSeries(self::postedDebits($period->from, $period->to)),
                    'borderColor' => IerpColors::CHART_PRIMARY,
                    'backgroundColor' => 'transparent',
                ],
                [
                    'label' => __('dashboards.charts.previous_period'),
                    'data' => $period->sumSeries(self::postedDebits($period->previousFrom, $period->previousTo), previous: true),
                    'borderColor' => IerpColors::CHART_NEUTRAL,
                    'backgroundColor' => 'transparent',
                    'borderDash' => [6, 4],
                ],
            ],
            'labels' => $period->labels(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    /** @return list<array{0: string, 1: numeric-string|int|float}> */
    private static function postedDebits(CarbonImmutable $from, CarbonImmutable $to): array
    {
        /** @var list<array{0: string, 1: numeric-string|int|float}> */
        return DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->whereDate('journal_entries.entry_date', '>=', $from->toDateString())
            ->whereDate('journal_entries.entry_date', '<=', $to->toDateString())
            ->get(['journal_entries.entry_date as entry_date', 'journal_entry_lines.debit as debit'])
            ->map(static fn (object $row): array => [$row->entry_date, $row->debit])
            ->all();
    }
}
