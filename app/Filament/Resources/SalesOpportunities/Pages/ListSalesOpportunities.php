<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesOpportunities\Pages;

use App\Filament\Resources\SalesOpportunities\SalesOpportunityResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListSalesOpportunities extends ListRecords
{
    protected static string $resource = SalesOpportunityResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('calendar')->label(__('Calendar'))->icon('heroicon-o-calendar-days')->url(SalesOpportunityResource::getUrl('calendar')), CreateAction::make()];
    }
}
