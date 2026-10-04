<?php

declare(strict_types=1);

namespace App\Filament\Resources\SlaCalendars\Pages;

use App\Filament\Resources\SlaCalendars\SlaCalendarResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditSlaCalendar extends EditRecord
{
    protected static string $resource = SlaCalendarResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
