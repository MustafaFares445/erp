<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tickets;

use App\Enums\MaintenanceStatus;
use App\Enums\QuotationStatus;
use App\Filament\LocalizedResource as Resource;
use App\Filament\RelationManagers\CollaborationEntriesRelationManager;
use App\Filament\RelationManagers\CustomFieldsRelationManager;
use App\Filament\Resources\Tickets\Pages\CreateTicket;
use App\Filament\Resources\Tickets\Pages\EditTicket;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Tickets\RelationManagers\AssignmentsRelationManager;
use App\Filament\Resources\Tickets\RelationManagers\MaintenanceRecordsRelationManager;
use App\Filament\Resources\Tickets\Schemas\TicketForm;
use App\Filament\Resources\Tickets\Schemas\TicketInfolist;
use App\Filament\Resources\Tickets\Tables\TicketsTable;
use App\Models\Ticket;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

final class TicketResource extends Resource
{
    protected static ?string $model = Ticket::class;

    protected static ?string $recordTitleAttribute = 'ticket_number';

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

    /** @return array<string> */
    #[\Override]
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'ticket_number',
            'title',
            'customer.company_name',
            'customer.customer_code',
            'description',
        ];
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
            MaintenanceRecordsRelationManager::class,
            CollaborationEntriesRelationManager::class,
            CustomFieldsRelationManager::class,
        ];
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withExists([
                'maintenanceRecords as has_active_maintenance' => static fn (Builder $query): Builder => $query->whereNotIn('status', [
                    MaintenanceStatus::Closed->value,
                    MaintenanceStatus::Cancelled->value,
                ]),
                'maintenanceRecords as has_maintenance_quotation_pending' => static fn (Builder $query): Builder => $query
                    ->where('status', MaintenanceStatus::AwaitingApproval->value)
                    ->whereHas('quotation', static fn (Builder $quotation): Builder => $quotation->whereIn('status', [
                        QuotationStatus::Sent->value,
                        QuotationStatus::ChangesRequested->value,
                    ])),
            ])
            ->with([
                'customer:id,company_name',
                'assignedEmployee.user:id,name',
                'supportTeam:id,name',
                'serializedInventoryUnit.productVariant:id,name',
                'paymentLink',
                'triagedBy:id,name',
            ])
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
