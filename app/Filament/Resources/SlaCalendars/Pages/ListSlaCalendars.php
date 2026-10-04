<?php

declare(strict_types=1);

namespace App\Filament\Resources\SlaCalendars\Pages;

use App\Filament\Resources\SlaCalendars\SlaCalendarResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListSlaCalendars extends ListRecords
{
    protected static string $resource = SlaCalendarResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
