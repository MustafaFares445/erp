<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Pages;

use App\Enums\InvoiceStatus;
use App\Filament\Concerns\ExportsSalesDocuments;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Widgets\InvoicesOverview;
use App\Models\Invoice;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ListInvoices extends ListRecords
{
    use ExportsSalesDocuments;

    protected static string $resource = InvoiceResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [$this->salesDocumentExportAction()];
    }

    #[\Override]
    protected function getHeaderWidgets(): array
    {
        return [InvoicesOverview::class];
    }

    /** @return array<string, Tab> */
    #[\Override]
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All')),
            'needs_attention' => Tab::make(__('Needs attention'))
                ->badge(Invoice::query()->needsAttention()->count())
                ->modifyQueryUsing(self::needsAttentionQuery(...)),
            'overdue' => Tab::make(__('Overdue'))
                ->badge(Invoice::query()->overdue()->count())
                ->modifyQueryUsing(self::overdueQuery(...)),
            'unpaid' => Tab::make(__('Unpaid'))
                ->badge(Invoice::query()->unpaid()->count())
                ->modifyQueryUsing(self::unpaidQuery(...)),
            'partially_paid' => Tab::make(__('Partially paid'))
                ->badge(Invoice::query()->partiallyPaid()->count())
                ->modifyQueryUsing(self::partiallyPaidQuery(...)),
            'draft' => Tab::make(__('Draft'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', InvoiceStatus::Draft->value)),
            'settled' => Tab::make(__('Settled'))
                ->modifyQueryUsing(self::settledQuery(...)),
            'issued_this_month' => Tab::make(__('Issued this month'))
                ->badge(Invoice::query()->issuedThisMonth()->count())
                ->modifyQueryUsing(self::issuedThisMonthQuery(...)),
        ];
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    private static function issuedThisMonthQuery(Builder $query): Builder
    {
        return $query->issuedThisMonth();
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    private static function unpaidQuery(Builder $query): Builder
    {
        return $query->unpaid();
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    private static function partiallyPaidQuery(Builder $query): Builder
    {
        return $query->partiallyPaid();
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    private static function overdueQuery(Builder $query): Builder
    {
        return $query->overdue();
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    private static function settledQuery(Builder $query): Builder
    {
        return $query->settled();
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    private static function needsAttentionQuery(Builder $query): Builder
    {
        return $query->needsAttention();
    }

    /** @return list<string> */
    private function salesDocumentExportHeadings(): array
    {
        return ['invoice_number', 'customer', 'invoice_date', 'due_date', 'total_amount', 'amount_paid', 'credited_amount', 'status'];
    }

    /** @return list<bool|float|int|string|null> */
    private function salesDocumentExportRow(Model $record): array
    {
        if (! $record instanceof Invoice) {
            return [];
        }

        return [
            (string) $record->invoice_number,
            $record->customer?->company_name,
            $record->invoice_date->toDateString(),
            $record->due_date?->toDateString(),
            (string) $record->total_amount,
            (string) $record->amount_paid,
            (string) $record->credited_amount,
            $record->status->value,
        ];
    }

    private function salesDocumentExportFilename(): string
    {
        return 'invoices-'.now()->format('Ymd-His').'.csv';
    }

    private function salesDocumentExportLogName(): string
    {
        return 'sales.invoice.exported';
    }
}
