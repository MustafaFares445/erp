<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalEntries;

use App\Filament\Resources\JournalEntries\Pages\CreateJournalEntry;
use App\Filament\Resources\JournalEntries\Pages\EditJournalEntry;
use App\Filament\Resources\JournalEntries\Pages\ListJournalEntries;
use App\Filament\Resources\JournalEntries\Pages\ViewJournalEntry;
use App\Filament\Resources\JournalEntries\Schemas\JournalEntryForm;
use App\Filament\Resources\JournalEntries\Schemas\JournalEntryInfolist;
use App\Filament\Resources\JournalEntries\Tables\JournalEntriesTable;
use App\Models\JournalEntry;
use App\Policies\JournalEntryPolicy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The only manual write path into the general ledger.
 *
 * A posted entry keeps its Edit route unreachable rather than removed: the route
 * exists, and {@see JournalEntryPolicy} refuses `update` for a
 * posted record, so an operator who guesses the URL is refused by the same rule
 * that hides the button.
 *
 * @see /specs/018-chart-of-accounts-journals/spec.md User Story 3, User Story 4
 */
final class JournalEntryResource extends Resource
{
    protected static ?string $model = JournalEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.accounting';

    protected static ?int $navigationSort = 202;

    protected static ?string $recordTitleAttribute = 'entry_number';

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.journal_entries');
    }

    #[\Override]
    public static function getModelLabel(): string
    {
        return __('admin.resources.journal_entries');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return JournalEntryForm::configure($schema);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return JournalEntryInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return JournalEntriesTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListJournalEntries::route('/'),
            'create' => CreateJournalEntry::route('/create'),
            'view' => ViewJournalEntry::route('/{record}'),
            'edit' => EditJournalEntry::route('/{record}/edit'),
        ];
    }
}
