<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerQuotationRequests\Tables;

use App\Enums\CustomerQuotationRequestStatus;
use App\Filament\Resources\CustomerQuotationRequests\Actions\CustomerQuotationRequestActions;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class CustomerQuotationRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('lines'))
            ->defaultSort('submitted_at', 'desc')
            ->columns([
                TextColumn::make('request_number')->label('Request'),
                TextColumn::make('customer.company_name')->label('Customer')->searchable(),
                TextColumn::make('lines_count')->label('Items')->alignEnd(),
                TextColumn::make('submitted_at')->dateTime(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (CustomerQuotationRequestStatus $state): string => $state->label())
                    ->color(fn (CustomerQuotationRequestStatus $state): string => $state->color()),
                TextColumn::make('resultingQuotation.quotation_number')->label('Quotation')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(fn (): array => array_combine(
                        array_map(fn (CustomerQuotationRequestStatus $status): string => $status->value, CustomerQuotationRequestStatus::cases()),
                        array_map(fn (CustomerQuotationRequestStatus $status): string => $status->label(), CustomerQuotationRequestStatus::cases()),
                    )),
            ])
            ->recordActions([
                ViewAction::make(),
                CustomerQuotationRequestActions::startReview(),
                CustomerQuotationRequestActions::convert(),
                CustomerQuotationRequestActions::reject(),
            ])
            ->toolbarActions([]);
    }
}
