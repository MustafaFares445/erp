<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderPaymentStatus;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Commercial detail on top of the order's existing fulfillment surface
 * (FR-028): its source quotation, payment term, stored totals, and priced
 * lines. Every money field placeholders rather than showing zero for an
 * order that predates this feature (research.md R-004).
 */
final class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('order_number')->label(__('admin.sales.fields.order_number')),
                TextEntry::make('customer.company_name')->label(__('admin.sales.fields.customer')),
                TextEntry::make('status')->label(__('admin.sales.fields.status'))->badge(),
                TextEntry::make('quotation.quotation_number')->label(__('admin.sales.fields.source_quotation'))->placeholder('—'),
                TextEntry::make('paymentTerm.name')->label(__('admin.sales.fields.payment_term'))->placeholder('—'),
                TextEntry::make('payment_status')
                    ->label(__('admin.sales.fields.payment_status'))
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(static fn (?OrderPaymentStatus $state): ?string => $state?->label()),
                TextEntry::make('subtotal')->label(__('admin.sales.fields.subtotal'))->numeric(decimalPlaces: 2)->placeholder('—'),
                TextEntry::make('tax_total')->label(__('admin.sales.fields.tax_total'))->numeric(decimalPlaces: 2)->placeholder('—'),
                TextEntry::make('grand_total')->label(__('admin.sales.fields.grand_total'))->numeric(decimalPlaces: 2)->placeholder('—'),
            ]),
            Section::make(__('admin.sales.fields.lines'))
                ->schema([
                    RepeatableEntry::make('lines')->label('')->columns(5)->schema([
                        TextEntry::make('productVariant.sku')->label(__('admin.sales.fields.product_variant')),
                        TextEntry::make('quantity')->label(__('admin.sales.fields.quantity')),
                        TextEntry::make('unit.name')->label(__('admin.sales.fields.unit'))->placeholder('—'),
                        TextEntry::make('unit_price')->label(__('admin.sales.fields.unit_price'))->numeric(decimalPlaces: 2)->placeholder('—'),
                        TextEntry::make('line_total')->label(__('admin.sales.fields.line_total'))->numeric(decimalPlaces: 2)->placeholder('—'),
                    ]),
                ]),
        ]);
    }
}
