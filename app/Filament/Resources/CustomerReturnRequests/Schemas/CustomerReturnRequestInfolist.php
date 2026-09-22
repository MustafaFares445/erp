<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerReturnRequests\Schemas;

use App\Enums\CustomerReturnRequestStatus;
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
                        TextEntry::make('request_number')->label('Request'),
                        TextEntry::make('customer.company_name')->label('Customer'),
                        TextEntry::make('originalOperation.operation_number')->label('Delivery')->placeholder('—'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (CustomerReturnRequestStatus $state): string => $state->label())
                            ->color(fn (CustomerReturnRequestStatus $state): string => $state->color()),
                        TextEntry::make('submitted_at')->dateTime(),
                        TextEntry::make('resultingInventoryReturn.return_number')->label('Linked inventory return')->placeholder('—'),
                        TextEntry::make('reviewedBy.name')->label('Reviewed by')->placeholder('—'),
                        TextEntry::make('reviewed_at')->dateTime()->placeholder('—'),
                        TextEntry::make('review_note')->label('Review note')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('reason')->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(3),
                Section::make('Items to return')
                    ->schema([
                        RepeatableEntry::make('lines')
                            ->label('')
                            ->schema([
                                TextEntry::make('originalOperationLine.productVariant.sku')->label('Variant'),
                                TextEntry::make('requested_quantity')->label('Quantity'),
                                TextEntry::make('customer_note')->label('Note')->placeholder('—'),
                            ])
                            ->columns(3),
                    ]),
            ]);
    }
}
