<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\AccountingPermission;
use App\Enums\JournalEntryStatus;
use App\Models\Bill;
use App\Models\Invoice;
use App\Models\JournalEntry;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class AccountingStatistics extends StatsOverviewWidget
{
    #[\Override]
    public static function canView(): bool
    {
        $user = auth()->user();

        return ($user?->can(AccountingPermission::JournalEntryView->value) ?? false)
            || ($user?->can(AccountingPermission::ReceivableView->value) ?? false)
            || ($user?->can(AccountingPermission::PayableView->value) ?? false);
    }

    #[\Override]
    protected function getStats(): array
    {
        $draftJournalEntries = JournalEntry::query()
            ->where('status', JournalEntryStatus::Draft->value)
            ->count();

        // Mirrors AccountsReceivableResource's real invoice lifecycle: an
        // invoice only carries a balance once issued.
        $receivablesOutstanding = $this->toFloat(
            Invoice::query()
                ->whereIn('status', ['issued', 'partially_paid'])
                ->selectRaw('COALESCE(SUM(total_amount - amount_paid), 0) as outstanding')
                ->value('outstanding')
        );

        // Mirrors AccountsPayableResource::getEloquentQuery()'s status filter.
        $payablesOutstanding = $this->toFloat(
            Bill::query()
                ->whereIn('status', ['approved', 'partially_paid'])
                ->selectRaw('COALESCE(SUM(total_amount - amount_paid), 0) as outstanding')
                ->value('outstanding')
        );

        // A bill has no dedicated "pending approval" status: BillPolicy::approve()
        // and BillResource's approve action both gate on Bill::isDraft(), so a
        // draft bill *is* the one awaiting approval.
        $billsPendingApproval = Bill::query()
            ->where('status', 'draft')
            ->count();

        return [
            Stat::make('Draft journal entries pending posting', $draftJournalEntries),
            Stat::make('Receivables outstanding', number_format((float) $receivablesOutstanding, 2)),
            Stat::make('Payables outstanding', number_format((float) $payablesOutstanding, 2)),
            Stat::make('Bills pending approval', $billsPendingApproval),
        ];
    }
}
