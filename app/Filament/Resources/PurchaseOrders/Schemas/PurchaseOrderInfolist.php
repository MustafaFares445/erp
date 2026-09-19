<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders\Schemas;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class PurchaseOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('purchase_order_number')->label(__('admin.purchasing.fields.purchase_order_number')),
                TextEntry::make('supplier.name')->label(__('admin.purchasing.fields.supplier')),
                TextEntry::make('status')
                    ->label(__('admin.purchasing.fields.status'))
                    ->badge()
                    ->formatStateUsing(static fn (PurchaseOrderStatus $state): string => $state->label()),
                TextEntry::make('currency_code')->label(__('admin.purchasing.fields.currency_code')),
                TextEntry::make('total_amount')
                    ->label(__('admin.purchasing.fields.total_amount'))
                    ->money(static fn (PurchaseOrder $record): string => $record->currency_code),
                TextEntry::make('ordered_at')->label(__('admin.purchasing.fields.ordered_at'))->date(),
                TextEntry::make('expected_at')->label(__('admin.purchasing.fields.expected_at'))->date()->placeholder('—'),                TextEntry::make('notes')->label(__('admin.purchasing.fields.notes'))->placeholder('—')->columnSpanFull(),
            ]),
            Section::make(__('admin.purchasing.fields.approved_by'))
                ->columns(3)
                ->visible(fn (PurchaseOrder $record): bool => $record->submitted_at !== null)
                ->schema([
                    TextEntry::make('submittedBy.name')->label(__('admin.purchasing.fields.submitted_by'))->placeholder('—'),
                    TextEntry::make('submitted_at')->label(__('admin.purchasing.fields.submitted_at'))->dateTime()->placeholder('—'),
                    TextEntry::make('approvedBy.name')->label(__('admin.purchasing.fields.approved_by'))->placeholder('—'),
                    TextEntry::make('approved_at')->label(__('admin.purchasing.fields.approved_at'))->dateTime()->placeholder('—'),
                    TextEntry::make('sent_at')->label(__('admin.purchasing.fields.sent_at'))->dateTime()->placeholder('—'),
                    TextEntry::make('rejection_reason')->label(__('admin.purchasing.fields.rejection_reason'))->placeholder('—'),
                    TextEntry::make('closure_reason')->label(__('admin.purchasing.fields.closure_reason'))->placeholder('—'),
                    TextEntry::make('cancellation_reason')->label(__('admin.purchasing.fields.cancellation_reason'))->placeholder('—'),
                ]),
            Section::make(__('admin.purchasing.fields.lines'))
                ->schema([
                    RepeatableEntry::make('lines')
                        ->label('')
                        ->columns(5)
                        ->schema([
                            TextEntry::make('productVariant.product.name')->label(__('admin.purchasing.fields.product')),
                            TextEntry::make('productVariant.name')->label(__('admin.purchasing.fields.product_variant')),
                            TextEntry::make('productVariant.product.brand.name')->label(__('admin.purchasing.fields.brand'))->placeholder('—'),
                            TextEntry::make('supplierProductReference.supplier_name')->label(__('admin.purchasing.fields.supplier_product_name'))->placeholder('—'),
                            TextEntry::make('supplier_item_number')->label(__('admin.purchasing.fields.supplier_item_number'))->placeholder('—'),                            TextEntry::make('unit.name')->label(__('admin.purchasing.fields.unit')),
                            TextEntry::make('quantity_ordered')
                                ->label(__('admin.purchasing.fields.quantity'))
                                ->numeric(decimalPlaces: 3),
                            TextEntry::make('unit_cost')
                                ->label(__('admin.purchasing.fields.unit_cost'))
                                ->money(static fn (PurchaseOrderLine $record): string => $record->purchaseOrder->currency_code),
                            TextEntry::make('line_total')
                                ->label(__('admin.purchasing.fields.line_total'))
                                ->money(static fn (PurchaseOrderLine $record): string => $record->purchaseOrder->currency_code),
                        ]),
                ]),
        ]);
    }
}
