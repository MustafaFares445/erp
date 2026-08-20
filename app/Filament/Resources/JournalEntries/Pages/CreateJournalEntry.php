<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalEntries\Pages;

use App\Filament\Concerns\InteractsWithAccountingServices;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

final class CreateJournalEntry extends CreateRecord
{
    use InteractsWithAccountingServices;

    protected static string $resource = JournalEntryResource::class;

    /**
     * Creates the entry itself through {@see JournalPostingService::draft()}, so
     * the entry number is allocated by the one method that knows how, and the
     * `create` ability is authorized by the service rather than only by the page.
     *
     * The lines arrive separately: the repeater is bound to the `lines`
     * relationship, so Filament writes them in `saveRelationships()` immediately
     * after this returns. That is deliberate — line rows are not ledger-affecting
     * while the entry is a draft, and once it is posted
     * {@see JournalEntryLine} refuses to be written at all.
     *
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        $actor = self::accountingActor();

        if (! $actor instanceof User) {
            throw new Halt;
        }

        return self::runAccountingOperation(
            fn (): JournalEntry => app(JournalPostingService::class)->draft(
                $actor,
                CarbonImmutable::parse(self::stringFrom($data['entry_date'] ?? null)),
                [],
                self::nullableStringFrom($data['description'] ?? null),
            ),
        );
    }
}
