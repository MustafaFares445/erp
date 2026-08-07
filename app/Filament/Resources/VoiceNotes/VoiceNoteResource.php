<?php

declare(strict_types=1);

namespace App\Filament\Resources\VoiceNotes;

use App\Filament\Resources\VoiceNotes\Pages\ListVoiceNotes;
use App\Filament\Resources\VoiceNotes\Pages\ViewVoiceNote;
use App\Filament\Resources\VoiceNotes\Schemas\VoiceNoteInfolist;
use App\Filament\Resources\VoiceNotes\Tables\VoiceNotesTable;
use App\Models\EmployeeVoiceNote;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class VoiceNoteResource extends Resource
{
    protected static ?string $model = EmployeeVoiceNote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMicrophone;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.employees';

    protected static ?int $navigationSort = 622;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.voice_notes');
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return VoiceNoteInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return VoiceNotesTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListVoiceNotes::route('/'),
            'view' => ViewVoiceNote::route('/{record}'),
        ];
    }
}
