<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalEntries\Pages;

use App\Filament\Resources\JournalEntries\Actions\JournalEntryActions;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Models\JournalEntry;
use App\Policies\JournalEntryPolicy;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Reachable only for a draft: {@see JournalEntryPolicy::update()}
 * refuses a posted entry regardless of permission, so no service call is needed
 * here to protect the ledger.
 *
 * The date and description are written by Filament directly, because editing a
 * draft is not a ledger-affecting operation — nothing about an unposted entry has
 * reached the books yet. {@see JournalEntry}'s `booted()` guard is the
 * backstop if that assumption is ever wrong.
 */
final class EditJournalEntry extends EditRecord
{
    protected static string $resource = JournalEntryResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            JournalEntryActions::post(),
            DeleteAction::make(),
        ];
    }
}
