<?php

declare(strict_types=1);

namespace App\Filament\Resources\Quotations\Schemas;

use App\Enums\QuotationResponseType;
use App\Enums\ResolvedPriceSource;
use App\Models\QuotationLine;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class QuotationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('quotation_number')->label(__('admin.sales.fields.quotation_number')),
            TextEntry::make('customer.company_name')->label(__('admin.sales.fields.customer')),
            TextEntry::make('status')->label(__('admin.sales.fields.status'))->badge(),
            TextEntry::make('issue_date')->label(__('admin.sales.fields.issue_date'))->date(),
            TextEntry::make('expires_at')->label(__('admin.sales.fields.expires_at'))->date(),
            TextEntry::make('subtotal')->label(__('admin.sales.fields.subtotal'))->money(),
            TextEntry::make('tax_total')->label(__('admin.sales.fields.tax_total'))->money(),
            TextEntry::make('grand_total')->label(__('admin.sales.fields.grand_total'))->money(),
            TextEntry::make('decision_note')->label(__('admin.sales.fields.decision_note'))->placeholder('—'),
            TextEntry::make('customerQuotationRequest.request_number')->label('Linked quote request')->placeholder('—'),
            RepeatableEntry::make('responses')
                ->label('Response history')
                ->schema([
                    TextEntry::make('response_type')
                        ->label('Response')
                        ->badge()
                        ->formatStateUsing(fn (QuotationResponseType $state): string => $state->label())
                        ->color(fn (QuotationResponseType $state): string => $state->color()),
                    TextEntry::make('respondedBy.name')->label('Responded by')->placeholder("Recorded on the customer's behalf"),
                    TextEntry::make('recordedBy.name')->label('Recorded by')->placeholder('—'),
                    TextEntry::make('source_channel')->label('Source'),
                    TextEntry::make('responded_at')->label('When')->dateTime(),
                    TextEntry::make('note')->label('Note')->placeholder('—'),
                ])
                ->columns(3)
                ->columnSpanFull(),
            RepeatableEntry::make('lines')
                ->label(__('admin.sales.fields.lines'))
                ->schema([
                    TextEntry::make('productVariant.sku')->label(__('admin.sales.fields.product_variant')),
                    TextEntry::make('quantity')->label(__('admin.sales.fields.quantity')),
                    TextEntry::make('unit.name')->label(__('admin.sales.fields.unit'))->placeholder('—'),
                    TextEntry::make('unit_price')->label(__('admin.sales.fields.unit_price'))->money(),
                    TextEntry::make('resolved_price_source')
                        ->label('Price source')
                        ->formatStateUsing(static fn (?ResolvedPriceSource $state): ?string => $state?->value)
                        ->placeholder('Legacy / unknown'),
                    TextEntry::make('resolvedPriceTier.name')->label('Pricing tier')->placeholder('—'),
                    TextEntry::make('list_price_minor')
                        ->label('List price snapshot')
                        ->state(static fn (QuotationLine $record): ?float => $record->list_price_minor === null ? null : $record->list_price_minor / 100)
                        ->money()
                        ->placeholder('—'),
                    TextEntry::make('floor_price_minor')
                        ->label('Floor snapshot')
                        ->state(static fn (QuotationLine $record): ?float => $record->floor_price_minor === null ? null : $record->floor_price_minor / 100)
                        ->money()
                        ->placeholder('—'),
                    TextEntry::make('priceFloorOverride.approvedBy.name')->label('Floor override approved by')->placeholder('—'),
                    TextEntry::make('tax_amount')->label(__('admin.sales.fields.tax_amount'))->money(),
                    TextEntry::make('line_total')->label(__('admin.sales.fields.line_total'))->money(),
                ])
                ->columns(4)
                ->columnSpanFull(),
        ])->columns(3);
    }
}
