<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes\Pages;

use App\Filament\Resources\CreditNotes\CreditNoteResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCreditNote extends CreateRecord
{
    protected static string $resource = CreditNoteResource::class;
}
