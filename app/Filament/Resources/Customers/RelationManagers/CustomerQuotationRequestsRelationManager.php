<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Enums\CustomerQuotationRequestStatus;
use App\Filament\Resources\CustomerQuotationRequests\CustomerQuotationRequestResource;
use App\Models\CustomerQuotationRequest;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only, same reasoning as {@see CustomerQuotationsRelationManager} — the
 * full resource is where review/conversion actions live.
 */
final class CustomerQuotationRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'quotationRequests';

    protected static ?string $title = 'Quote Requests';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('request_number')->label('Request'),
                TextColumn::make('submitted_at')->dateTime(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (CustomerQuotationRequestStatus $state): string => $state->label())
                    ->color(fn (CustomerQuotationRequestStatus $state): string => $state->color()),
                TextColumn::make('resultingQuotation.quotation_number')->label('Quotation')->placeholder('—'),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->recordUrl(fn (CustomerQuotationRequest $record): string => CustomerQuotationRequestResource::getUrl('view', ['record' => $record]))
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
