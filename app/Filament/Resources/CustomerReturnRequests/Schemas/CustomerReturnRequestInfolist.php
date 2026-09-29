<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerReturnRequests\Schemas;

use App\Enums\CustomerReturnRequestStatus;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\Returns\ReturnResource;
use App\Models\CustomerReturnRequest;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class CustomerReturnRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextEntry::make('request_number')->label(__('admin.inventory.customer_return_request.fields.request')),
                        TextEntry::make('customer.company_name')->label(__('admin.inventory.customer_return_request.fields.customer')),
                        TextEntry::make('originalOperation.operation_number')
                            ->label(__('admin.inventory.customer_return_request.fields.delivery'))
                            ->placeholder('—')
                            ->url(fn (CustomerReturnRequest $record): ?string => $record->original_inventory_operation_id
                                ? InventoryOperationResource::getUrl('view', ['record' => $record->original_inventory_operation_id])
                                : null),
                        TextEntry::make('status')
                            ->label(__('admin.inventory.customer_return_request.fields.status'))
                            ->badge()
                            ->formatStateUsing(fn (CustomerReturnRequestStatus $state): string => $state->label())
                            ->color(fn (CustomerReturnRequestStatus $state): string => $state->color()),
                        TextEntry::make('submitted_at')->label(__('admin.inventory.customer_return_request.fields.submitted_at'))->dateTime(),
                        TextEntry::make('resultingInventoryReturn.return_number')
                            ->label(__('admin.inventory.customer_return_request.fields.inventory_return'))
                            ->placeholder('—')
                            ->url(fn (CustomerReturnRequest $record): ?string => $record->resulting_inventory_return_id
                                ? ReturnResource::getUrl('view', ['record' => $record->resulting_inventory_return_id])
                                : null),
                        TextEntry::make('reviewedBy.name')->label(__('admin.inventory.customer_return_request.fields.reviewed_by'))->placeholder('—'),
                        TextEntry::make('reviewed_at')->label(__('admin.inventory.customer_return_request.fields.reviewed_at'))->dateTime()->placeholder('—'),
                        TextEntry::make('review_note')->label(__('admin.inventory.customer_return_request.fields.review_note'))->placeholder('—')->columnSpanFull(),
                        TextEntry::make('reason')->label(__('admin.inventory.customer_return_request.fields.reason'))->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(3),
                Section::make(__('admin.inventory.customer_return_request.sections.items'))
                    ->schema([
                        RepeatableEntry::make('lines')
                            ->label('')
                            ->schema([
                                TextEntry::make('originalOperationLine.productVariant.sku')->label(__('admin.inventory.customer_return_request.fields.variant')),
                                TextEntry::make('requested_quantity')->label(__('admin.inventory.customer_return_request.fields.quantity')),
                                TextEntry::make('customer_note')->label(__('admin.inventory.customer_return_request.fields.note'))->placeholder('—'),
                            ])
                            ->columns(3),
                    ]),
            ]);
    }
}
