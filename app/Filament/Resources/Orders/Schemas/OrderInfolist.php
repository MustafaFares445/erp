<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderPaymentStatus;
use App\Enums\ResolvedPriceSource;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Sales order')->columns(3)->schema([
                TextEntry::make('order_number')->label(__('admin.sales.fields.order_number')),
                TextEntry::make('customer.company_name')->label(__('admin.sales.fields.customer')),
                TextEntry::make('status')->label(__('admin.sales.fields.status'))->badge(),
                TextEntry::make('pending_reason')->label('Blocking reason')->placeholder('—')->columnSpanFull(),
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
            Section::make(__('admin.sales.fields.lines'))->schema([
                RepeatableEntry::make('lines')->label('')->columns(4)->schema([
                    TextEntry::make('productVariant.sku')->label(__('admin.sales.fields.product_variant')),
                    TextEntry::make('quantity')->label(__('admin.sales.fields.quantity')),
                    TextEntry::make('unit.name')->label(__('admin.sales.fields.unit'))->placeholder('—'),
                    TextEntry::make('unit_price')->label(__('admin.sales.fields.unit_price'))->numeric(decimalPlaces: 2)->placeholder('—'),
                    TextEntry::make('resolved_price_source')
                        ->label('Price source')
                        ->formatStateUsing(static fn (?ResolvedPriceSource $state): ?string => $state?->value)
                        ->placeholder('Legacy / unknown'),
                    TextEntry::make('resolvedPriceTier.name')->label('Pricing tier')->placeholder('—'),
                    TextEntry::make('list_price_minor')
                        ->label('List price snapshot')
                        ->state(static fn ($record): ?float => $record->list_price_minor === null ? null : $record->list_price_minor / 100)
                        ->money()
                        ->placeholder('—'),
                    TextEntry::make('floor_price_minor')
                        ->label('Floor snapshot')
                        ->state(static fn ($record): ?float => $record->floor_price_minor === null ? null : $record->floor_price_minor / 100)
                        ->money()
                        ->placeholder('—'),
                    TextEntry::make('priceFloorOverride.approvedBy.name')->label('Floor override approved by')->placeholder('—'),
                    TextEntry::make('tax_amount')->label(__('admin.sales.fields.tax_total'))->numeric(decimalPlaces: 2)->placeholder('—'),
                    TextEntry::make('line_total')->label(__('admin.sales.fields.line_total'))->numeric(decimalPlaces: 2)->placeholder('—'),
                ]),
            ]),
            Section::make('Fulfillment')->schema([
                RepeatableEntry::make('deliveries')->label('Delivery notes')->columns(4)->schema([
                    TextEntry::make('operation_number')->label('Delivery'),
                    TextEntry::make('sourceWarehouse.name')->label('Warehouse'),
                    TextEntry::make('stage')->badge(),
                    TextEntry::make('scheduled_at')->dateTime()->placeholder('—'),
                ]),
                RepeatableEntry::make('shipments')->label('Shipments')->columns(3)->schema([
                    TextEntry::make('tracking_number')->label('Tracking'),
                    TextEntry::make('warehouse.name')->label('Warehouse'),
                    TextEntry::make('status')->badge(),
                ]),
            ]),
            Section::make('Procurement')->schema([
                RepeatableEntry::make('procurementRequirements')->label('')->columns(6)->schema([
                    TextEntry::make('productVariant.sku')->label('Product'),
                    TextEntry::make('required_base_quantity')->label('Shortage'),
                    TextEntry::make('fulfilled_base_quantity')->label('Received'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('purchaseOrder.purchase_order_number')->label('Purchase order')->placeholder('—'),
                    TextEntry::make('supplierConfirmation.confirmation_status')->label('Supplier confirmation')->badge()->placeholder('—'),
                ]),
            ]),
            Section::make('Financial')->schema([
                RepeatableEntry::make('invoices')->label('Invoices')->columns(5)->schema([
                    TextEntry::make('invoice_number')->label('Invoice'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('total_amount')->numeric(decimalPlaces: 2),
                    TextEntry::make('amount_paid')->numeric(decimalPlaces: 2),
                    TextEntry::make('credited_amount')->numeric(decimalPlaces: 2),
                ]),
            ]),
        ]);
    }
}
