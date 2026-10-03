<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseAgreements\Pages;

use App\Filament\Concerns\HasTableViewTabs;
use App\Filament\Concerns\PersistsTablePresentation;
use App\Filament\Resources\PurchaseAgreements\PurchaseAgreementResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

final class ListPurchaseAgreements extends ListRecords
{
    use HasTableViewTabs;
    use PersistsTablePresentation;

    protected static string $resource = PurchaseAgreementResource::class;

    protected function savedTableViewPageKey(): string
    {
        return 'purchasing.purchase-agreements';
    }

    /** @return array<string, Tab> */
    #[\Override]
    public function getTabs(): array
    {
        return ['all' => Tab::make(__('All'))];
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
