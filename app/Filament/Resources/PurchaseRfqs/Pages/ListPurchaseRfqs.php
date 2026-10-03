<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseRfqs\Pages;

use App\Enums\PurchaseRfqStatus;
use App\Filament\Concerns\HasTableViewTabs;
use App\Filament\Concerns\PersistsTablePresentation;
use App\Filament\Resources\PurchaseRfqs\PurchaseRfqResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

final class ListPurchaseRfqs extends ListRecords
{
    use HasTableViewTabs;
    use PersistsTablePresentation;

    protected static string $resource = PurchaseRfqResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New RFQ'))];
    }

    protected function savedTableViewPageKey(): string
    {
        return 'purchasing.purchase-rfqs';
    }

    /** @return array<string, Tab> */
    #[\Override]
    public function getTabs(): array
    {
        $openStatuses = array_values(array_filter(
            PurchaseRfqStatus::cases(),
            static fn (PurchaseRfqStatus $status): bool => ! $status->isTerminal(),
        ));

        return [
            'all' => Tab::make(__('Default'))->icon(Heroicon::OutlinedQueueList),
            'mine' => Tab::make(__('My RFQs'))
                ->icon(Heroicon::OutlinedUser)
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('requested_by', auth()->id())),
            'open' => Tab::make(__('Open'))
                ->icon(Heroicon::OutlinedDocumentMagnifyingGlass)
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->whereIn('status', $openStatuses)),
        ];
    }
}
