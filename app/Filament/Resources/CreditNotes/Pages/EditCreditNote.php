<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes\Pages;

use App\Filament\Resources\CreditNotes\CreditNoteResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditCreditNote extends EditRecord
{
    protected static string $resource = CreditNoteResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
