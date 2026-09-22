<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Enums\CustomerReturnRequestStatus;
use App\Filament\Resources\CustomerReturnRequests\CustomerReturnRequestResource;
use App\Models\CustomerReturnRequest;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only, same reasoning as {@see CustomerQuotationRequestsRelationManager}
 * — the full resource is where review/conversion actions live.
 */
final class CustomerReturnRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'returnRequests';

    protected static ?string $title = 'Return Requests';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('request_number')->label('Request'),
                TextColumn::make('submitted_at')->dateTime(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (CustomerReturnRequestStatus $state): string => $state->label())
                    ->color(fn (CustomerReturnRequestStatus $state): string => $state->color()),
                TextColumn::make('resultingInventoryReturn.return_number')->label('Return')->placeholder('—'),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->recordUrl(fn (CustomerReturnRequest $record): string => CustomerReturnRequestResource::getUrl('view', ['record' => $record]))
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
