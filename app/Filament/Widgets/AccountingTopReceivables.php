<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\AccountingPermission;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Widgets\Concerns\BuildsDashboardTables;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Models\Invoice;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\MoneyFormatter;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Customers ranked by their live receivable balance — the same per-invoice
 * `outstandingMinor()` the receivables card totals — so the accountant sees
 * who to chase first.
 */
final class AccountingTopReceivables extends TableWidget
{
    use BuildsDashboardTables;
    use InteractsWithDashboardFilters;

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(AccountingPermission::ReceivableView->value) ?? false;
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $this->dashboardTable($table)
            ->heading(__('dashboards.accounting.tables.top_receivables'))
            ->records(fn (int $page, int $recordsPerPage): LengthAwarePaginator => self::paginateRows(
                self::rows(),
                $page,
                $recordsPerPage,
            ))
            ->recordUrl(fn (array $record): string => CustomerResource::getUrl('view', ['record' => $record['customer_id']]))
            ->columns([
                TextColumn::make('customer')
                    ->label(__('dashboards.accounting.columns.customer'))
                    ->weight('medium'),
                TextColumn::make('invoices')
                    ->label(__('dashboards.accounting.columns.open_invoices'))
                    ->numeric(),
                TextColumn::make('outstanding_minor')
                    ->label(__('dashboards.accounting.columns.outstanding'))
                    ->formatStateUsing(fn (int $state): string => MoneyFormatter::format($state)),
            ]);
    }

    /** @return array<int|string, array{customer_id: int, customer: string, invoices: int, outstanding_minor: int}> */
    private static function rows(): array
    {
        return Invoice::query()
            ->with(['writeOffs', 'customer:id,company_name,customer_code'])
            ->whereNotNull('issued_at')
            ->get()
            ->map(fn (Invoice $invoice): array => ['invoice' => $invoice, 'outstanding' => $invoice->outstandingMinor()])
            ->filter(fn (array $row): bool => $row['outstanding'] > 0)
            ->groupBy(fn (array $row): int => (int) $row['invoice']->customer_id)
            ->map(fn (Collection $rows, int $customerId): array => [
                'customer_id' => $customerId,
                'customer' => (string) ($rows->first()['invoice']->customer->company_name ?? __('dashboards.fallback.customer', ['id' => $customerId])),
                'invoices' => $rows->count(),
                'outstanding_minor' => DashboardPeriod::toInt($rows->sum('outstanding')),
            ])
            ->sortByDesc('outstanding_minor')
            ->all();
    }
}
