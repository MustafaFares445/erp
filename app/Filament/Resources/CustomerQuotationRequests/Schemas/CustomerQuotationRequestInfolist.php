<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerQuotationRequests\Schemas;

use App\Enums\CustomerQuotationRequestStatus;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class CustomerQuotationRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextEntry::make('request_number')->label(__('Request')),
                        TextEntry::make('customer.company_name')->label(__('Customer')),
                        TextEntry::make('deliveryAddress.label')->label(__('Delivery address'))->placeholder(__('—')),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (CustomerQuotationRequestStatus $state): string => $state->label())
                            ->color(fn (CustomerQuotationRequestStatus $state): string => $state->color()),
                        TextEntry::make('submitted_at')->dateTime(),
                        TextEntry::make('resultingQuotation.quotation_number')->label(__('Linked quotation'))->placeholder(__('—')),
                        TextEntry::make('reviewedBy.name')->label(__('Reviewed by'))->placeholder(__('—')),
                        TextEntry::make('reviewed_at')->dateTime()->placeholder(__('—')),
                        TextEntry::make('review_note')->label(__('Review note'))->placeholder(__('—'))->columnSpanFull(),
                        TextEntry::make('notes')->placeholder(__('—'))->columnSpanFull(),
                    ])
                    ->columns(3),
                Section::make(__('Requested products'))
                    ->schema([
                        RepeatableEntry::make('lines')
                            ->label('')
                            ->schema([
                                TextEntry::make('productVariant.sku')->label(__('Variant')),
                                TextEntry::make('requested_quantity')->label(__('Quantity')),
                                TextEntry::make('requestedUnit.symbol')->label(__('Unit'))->placeholder(__('Base unit')),
                                TextEntry::make('customer_note')->label(__('Note'))->placeholder(__('—')),
                            ])
                            ->columns(4),
                    ]),
            ]);
    }
}
