<?php

declare(strict_types=1);

namespace App\Filament\Resources\Visits\Pages;

use App\Filament\Concerns\HasSavedTableViews;
use App\Filament\Concerns\PersistsTablePresentation;
use App\Filament\Resources\Visits\VisitResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

final class ListVisits extends ListRecords
{
    use HasSavedTableViews;
    use PersistsTablePresentation;

    protected static string $resource = VisitResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('calendar')->label(__('Calendar'))->icon('heroicon-o-calendar-days')->url(VisitResource::getUrl('calendar')),
            ...$this->savedTableViewActions(),
        ];
    }

    protected function savedTableViewPageKey(): string
    {
        return 'employees.visits';
    }
}
