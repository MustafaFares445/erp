<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Ticket;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only (WP-3.1, GAP-UI-03, CR-05) — the link out is the only action.
 */
final class CustomerTicketsRelationManager extends RelationManager
{
    protected static string $relationship = 'tickets';

    protected static ?string $title = 'Tickets';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('ticket_number')->label('Ticket'),
                TextColumn::make('created_at')->dateTime(),
                TextColumn::make('status')->badge(),
                TextColumn::make('priority')->badge(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (Ticket $record): string => TicketResource::getUrl('view', ['record' => $record]))
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
