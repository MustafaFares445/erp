<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes;

use App\Filament\Resources\CreditNotes\Pages\CreateCreditNote;
use App\Filament\Resources\CreditNotes\Pages\EditCreditNote;
use App\Filament\Resources\CreditNotes\Pages\ListCreditNotes;
use App\Filament\Resources\CreditNotes\Pages\ViewCreditNote;
use App\Filament\Resources\CreditNotes\Schemas\CreditNoteForm;
use App\Filament\Resources\CreditNotes\Schemas\CreditNoteInfolist;
use App\Filament\Resources\CreditNotes\Tables\CreditNotesTable;
use App\Models\CreditNote;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

final class CreditNoteResource extends Resource
{
    protected static ?string $model = CreditNote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.sales';

    protected static ?int $navigationSort = 105;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.credit_notes');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return CreditNoteForm::configure($schema);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return CreditNoteInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return CreditNotesTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListCreditNotes::route('/'),
            'create' => CreateCreditNote::route('/create'),
            'view' => ViewCreditNote::route('/{record}'),
            'edit' => EditCreditNote::route('/{record}/edit'),
        ];
    }

    #[\Override]
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
