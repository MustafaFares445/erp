<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tickets;

use App\Filament\Resources\Tickets\Pages\CreateTicket;
use App\Filament\Resources\Tickets\Pages\EditTicket;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Tickets\RelationManagers\AssignmentsRelationManager;
use App\Filament\Resources\Tickets\RelationManagers\MessagesRelationManager;
use App\Filament\Resources\Tickets\Schemas\TicketForm;
use App\Filament\Resources\Tickets\Schemas\TicketInfolist;
use App\Filament\Resources\Tickets\Tables\TicketsTable;
use App\Models\Ticket;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

final class TicketResource extends Resource
{
    protected static ?string $model = Ticket::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.support';

    protected static ?int $navigationSort = 701;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.tickets');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return TicketForm::configure($schema);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return TicketInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return TicketsTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListTickets::route('/'),
            'create' => CreateTicket::route('/create'),
            'view' => ViewTicket::route('/{record}'),
            'edit' => EditTicket::route('/{record}/edit'),
        ];
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            AssignmentsRelationManager::class,
            MessagesRelationManager::class,
        ];
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['customer:id,company_name', 'assignedEmployee.user:id,name'])
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
