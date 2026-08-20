<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalEntries\Pages;

use App\Filament\Resources\JournalEntries\Actions\JournalEntryActions;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewJournalEntry extends ViewRecord
{
    protected static string $resource = JournalEntryResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            JournalEntryActions::post(),
            JournalEntryActions::reverse(),
        ];
    }
}
