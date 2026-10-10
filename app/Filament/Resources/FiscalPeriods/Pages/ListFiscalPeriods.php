<?php

declare(strict_types=1);

namespace App\Filament\Resources\FiscalPeriods\Pages;

use App\Filament\Resources\FiscalPeriods\FiscalPeriodResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListFiscalPeriods extends ListRecords
{
    protected static string $resource = FiscalPeriodResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            Action::make('calendar')->label(__('Calendar'))->icon('heroicon-o-calendar-days')->url(FiscalPeriodResource::getUrl('calendar')), CreateAction::make()];
    }
}
