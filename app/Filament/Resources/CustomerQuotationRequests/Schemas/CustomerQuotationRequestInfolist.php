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
                        TextEntry::make('request_number')->label('Request'),
                        TextEntry::make('customer.company_name')->label('Customer'),
                        TextEntry::make('deliveryAddress.label')->label('Delivery address')->placeholder('—'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (CustomerQuotationRequestStatus $state): string => $state->label())
                            ->color(fn (CustomerQuotationRequestStatus $state): string => $state->color()),
                        TextEntry::make('submitted_at')->dateTime(),
                        TextEntry::make('resultingQuotation.quotation_number')->label('Linked quotation')->placeholder('—'),
                        TextEntry::make('reviewedBy.name')->label('Reviewed by')->placeholder('—'),
                        TextEntry::make('reviewed_at')->dateTime()->placeholder('—'),
                        TextEntry::make('review_note')->label('Review note')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('notes')->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(3),
                Section::make('Requested products')
                    ->schema([
                        RepeatableEntry::make('lines')
                            ->label('')
                            ->schema([
                                TextEntry::make('productVariant.sku')->label('Variant'),
                                TextEntry::make('requested_quantity')->label('Quantity'),
                                TextEntry::make('requestedUnit.symbol')->label('Unit')->placeholder('Base unit'),
                                TextEntry::make('customer_note')->label('Note')->placeholder('—'),
                            ])
                            ->columns(4),
                    ]),
            ]);
    }
}
