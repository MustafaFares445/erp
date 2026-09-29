<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerReturnRequests\Tables;

use App\Enums\CustomerReturnRequestStatus;
use App\Filament\Resources\CustomerReturnRequests\Actions\CustomerReturnRequestActions;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\Returns\ReturnResource;
use App\Models\CustomerReturnRequest;
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
                TextColumn::make('request_number')->label(__('admin.inventory.customer_return_request.fields.request')),
                TextColumn::make('customer.company_name')->label(__('admin.inventory.customer_return_request.fields.customer'))->searchable(),
                TextColumn::make('originalOperation.operation_number')
                    ->label(__('admin.inventory.customer_return_request.fields.delivery'))
                    ->placeholder('—')
                    ->url(fn (CustomerReturnRequest $record): ?string => $record->original_inventory_operation_id
                        ? InventoryOperationResource::getUrl('view', ['record' => $record->original_inventory_operation_id])
                        : null),
                TextColumn::make('lines_count')->label(__('admin.inventory.customer_return_request.fields.items'))->alignEnd(),
                TextColumn::make('submitted_at')->label(__('admin.inventory.customer_return_request.fields.submitted_at'))->dateTime(),
                TextColumn::make('status')
                    ->label(__('admin.inventory.customer_return_request.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (CustomerReturnRequestStatus $state): string => $state->label())
                    ->color(fn (CustomerReturnRequestStatus $state): string => $state->color()),
                TextColumn::make('resultingInventoryReturn.return_number')
                    ->label(__('admin.inventory.customer_return_request.fields.inventory_return'))
                    ->placeholder('—')
                    ->url(fn (CustomerReturnRequest $record): ?string => $record->resulting_inventory_return_id
                        ? ReturnResource::getUrl('view', ['record' => $record->resulting_inventory_return_id])
                        : null),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.inventory.customer_return_request.fields.status'))
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
