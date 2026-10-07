<?php

declare(strict_types=1);

namespace App\Filament\Resources\Quotations\Pages;

use App\Enums\QuotationStatus;
use App\Filament\Concerns\ExportsSalesDocuments;
use App\Filament\Concerns\HasTableViewTabs;
use App\Filament\Concerns\PersistsTablePresentation;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\Quotations\Widgets\QuotationsOverview;
use App\Models\Quotation;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ListQuotations extends ListRecords
{
    use ExportsSalesDocuments;
    use HasTableViewTabs;
    use PersistsTablePresentation;

    protected static string $resource = QuotationResource::class;

    protected function savedTableViewPageKey(): string
    {
        return 'sales.quotations';
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
        return [QuotationsOverview::class];
    }

    /** @return array<string, Tab> */
    #[\Override]
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All')),
            'draft' => Tab::make(__('Draft'))
                ->badge(Quotation::query()->where('status', QuotationStatus::Draft->value)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', QuotationStatus::Draft->value)),
            'awaiting_decision' => Tab::make(__('Sent / Awaiting decision'))
                ->badge(Quotation::query()->awaitingDecision()->count())
                ->modifyQueryUsing(self::awaitingDecisionQuery(...)),
            'accepted' => Tab::make(__('Accepted'))
                ->badge(Quotation::query()->where('status', QuotationStatus::Accepted->value)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', QuotationStatus::Accepted->value)),
            'expiring_soon' => Tab::make(__('Expiring soon'))
                ->badge(Quotation::query()->expiringSoon()->count())
                ->modifyQueryUsing(self::expiringSoonQuery(...)),
            'open' => Tab::make(__('Open'))
                ->modifyQueryUsing(self::openQuery(...)),
            'converted' => Tab::make(__('Converted'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', QuotationStatus::ConvertedToOrder->value)),
            'rejected_cancelled' => Tab::make(__('Rejected / Cancelled'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    QuotationStatus::Rejected->value,
                    QuotationStatus::Cancelled->value,
                ])),
        ];
    }

    /**
     * @param  Builder<Quotation>  $query
     * @return Builder<Quotation>
     */
    private static function awaitingDecisionQuery(Builder $query): Builder
    {
        return $query->awaitingDecision();
    }

    /**
     * @param  Builder<Quotation>  $query
     * @return Builder<Quotation>
     */
    private static function expiringSoonQuery(Builder $query): Builder
    {
        return $query->expiringSoon();
    }

    /**
     * @param  Builder<Quotation>  $query
     * @return Builder<Quotation>
     */
    private static function openQuery(Builder $query): Builder
    {
        return $query->open();
    }

    /** @return list<string> */
    private function salesDocumentExportHeadings(): array
    {
        return ['quotation_number', 'customer', 'status', 'issue_date', 'expires_at', 'grand_total'];
    }

    /** @return list<bool|float|int|string|null> */
    private function salesDocumentExportRow(Model $record): array
    {
        if (! $record instanceof Quotation) {
            return [];
        }

        return [
            (string) $record->quotation_number,
            $record->customer?->company_name,
            $record->status->value,
            $record->issue_date->toDateString(),
            $record->expires_at?->toDateString(),
            (string) $record->grand_total,
        ];
    }

    private function salesDocumentExportFilename(): string
    {
        return 'quotations-'.now()->format('Ymd-His').'.csv';
    }

    private function salesDocumentExportLogName(): string
    {
        return 'sales.quotation.exported';
    }
}
