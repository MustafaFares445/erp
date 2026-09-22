<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerReturnRequests\Tables;

use App\Enums\CustomerReturnRequestStatus;
use App\Filament\Resources\CustomerReturnRequests\Actions\CustomerReturnRequestActions;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class CustomerReturnRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('lines'))
            ->defaultSort('submitted_at', 'desc')
            ->columns([
                TextColumn::make('request_number')->label('Request'),
                TextColumn::make('customer.company_name')->label('Customer')->searchable(),
                TextColumn::make('originalOperation.operation_number')->label('Delivery')->placeholder('—'),
                TextColumn::make('lines_count')->label('Items')->alignEnd(),
                TextColumn::make('submitted_at')->dateTime(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (CustomerReturnRequestStatus $state): string => $state->label())
                    ->color(fn (CustomerReturnRequestStatus $state): string => $state->color()),
                TextColumn::make('resultingInventoryReturn.return_number')->label('Return')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(fn (): array => array_combine(
                        array_map(fn (CustomerReturnRequestStatus $status): string => $status->value, CustomerReturnRequestStatus::cases()),
                        array_map(fn (CustomerReturnRequestStatus $status): string => $status->label(), CustomerReturnRequestStatus::cases()),
                    )),
            ])
            ->recordActions([
                ViewAction::make(),
                CustomerReturnRequestActions::startReview(),
                CustomerReturnRequestActions::approveAndConvert(),
                CustomerReturnRequestActions::retryConvert(),
                CustomerReturnRequestActions::reject(),
            ])
            ->toolbarActions([]);
    }
}
