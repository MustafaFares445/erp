<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes\Pages;

use App\Enums\CreditNoteReason;
use App\Enums\CreditNoteStatus;
use App\Filament\Concerns\ExportsSalesDocuments;
use App\Filament\Concerns\HasTableViewTabs;
use App\Filament\Concerns\PersistsTablePresentation;
use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Resources\CreditNotes\Widgets\CreditNotesOverview;
use App\Models\CreditNote;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ListCreditNotes extends ListRecords
{
    use ExportsSalesDocuments;
    use HasTableViewTabs;
    use PersistsTablePresentation;

    protected static string $resource = CreditNoteResource::class;

    protected function savedTableViewPageKey(): string
    {
        return 'sales.credit-notes';
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            $this->salesDocumentExportAction(),
        ];
    }

    #[\Override]
    protected function getHeaderWidgets(): array
    {
        return [CreditNotesOverview::class];
    }

    /** @return array<string, Tab> */
    #[\Override]
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('admin.sales.credit_note_tabs.all')),
            'draft' => Tab::make(__('admin.sales.credit_note_tabs.draft'))
                ->badge(CreditNote::query()->where('status', CreditNoteStatus::Draft->value)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', CreditNoteStatus::Draft->value)),
            'confirmed_this_month' => Tab::make(__('admin.sales.credit_note_tabs.confirmed_this_month'))
                ->badge(CreditNote::query()->confirmedThisMonth()->count())
                ->modifyQueryUsing(self::confirmedThisMonthQuery(...)),
            'confirmed' => Tab::make(__('admin.sales.credit_note_tabs.confirmed'))
                ->badge(CreditNote::query()->where('status', CreditNoteStatus::Confirmed->value)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', CreditNoteStatus::Confirmed->value)),
            'sales_returns' => Tab::make(__('admin.sales.credit_note_tabs.sales_returns'))
                ->badge(CreditNote::query()->where('reason_category', CreditNoteReason::SalesReturn->value)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('reason_category', CreditNoteReason::SalesReturn->value)),
            'reversed_cancelled' => Tab::make(__('admin.sales.credit_note_tabs.reversed_cancelled'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    CreditNoteStatus::Reversed->value,
                    CreditNoteStatus::Cancelled->value,
                ])),
        ];
    }

    /**
     * @param  Builder<CreditNote>  $query
     * @return Builder<CreditNote>
     */
    private static function confirmedThisMonthQuery(Builder $query): Builder
    {
        return $query->confirmedThisMonth();
    }

    /** @return list<string> */
    protected function salesDocumentExportHeadings(): array
    {
        return ['credit_note_number', 'customer', 'invoice_number', 'issue_date', 'grand_total', 'status'];
    }

    /** @return list<bool|float|int|string|null> */
    protected function salesDocumentExportRow(Model $record): array
    {
        if (! $record instanceof CreditNote) {
            return [];
        }

        return [
            (string) $record->credit_note_number,
            $record->customer?->company_name,
            $record->invoice?->invoice_number,
            $record->issue_date->toDateString(),
            (string) $record->grand_total,
            $record->status->label(),
        ];
    }

    protected function salesDocumentExportFilename(): string
    {
        return 'credit-notes-'.now()->format('Ymd-His').'.csv';
    }

    protected function salesDocumentExportLogName(): string
    {
        return 'sales.credit_note.exported';
    }
}
