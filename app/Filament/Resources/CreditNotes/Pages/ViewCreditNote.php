<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes\Pages;

use App\Filament\Resources\CreditNotes\CreditNoteResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewCreditNote extends ViewRecord
{
    protected static string $resource = CreditNoteResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
