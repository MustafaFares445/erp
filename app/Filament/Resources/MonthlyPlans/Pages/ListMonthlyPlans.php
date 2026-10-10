<?php

declare(strict_types=1);

namespace App\Filament\Resources\MonthlyPlans\Pages;

use App\Filament\Resources\MonthlyPlans\MonthlyPlanResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListMonthlyPlans extends ListRecords
{
    protected static string $resource = MonthlyPlanResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            Action::make('calendar')->label(__('Calendar'))->icon('heroicon-o-calendar-days')->url(MonthlyPlanResource::getUrl('calendar')),
            CreateAction::make(),
        ];
    }
}
