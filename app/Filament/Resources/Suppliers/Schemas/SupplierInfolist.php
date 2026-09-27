<?php

declare(strict_types=1);

namespace App\Filament\Resources\Suppliers\Schemas;

use App\Enums\BillStatus;
use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\Bill;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\SupplierProductSupport;
use App\Support\QuantityFormatter;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

final class SupplierInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Supplier profile')
                ->description('Commercial supplier identity and default procurement policy.')
                ->columns(4)
                ->schema([
                    TextEntry::make('name')->label('Supplier'),
                    TextEntry::make('code')->label('Code'),
                    TextEntry::make('is_active')
                        ->label('Status')
                        ->state(fn (Supplier $record): string => $record->is_active ? 'Active' : 'Inactive')
                        ->badge()
                        ->color(fn (Supplier $record): string => $record->is_active ? 'success' : 'gray'),
                    TextEntry::make('requires_confirmation')
                        ->label('Default confirmation policy')
                        ->state(fn (Supplier $record): string => $record->requires_confirmation ? 'Required' : 'Not required')
                        ->badge(),
                    TextEntry::make('email')->label('Email')->placeholder('—'),
                    TextEntry::make('phone')->label('Phone')->placeholder('—'),
                    TextEntry::make('address')->label('Address')->placeholder('—')->columnSpan(2),
                ]),

            Section::make('Supplier capabilities')
                ->description('Showing up to 10 active capabilities. Capability answers whether the supplier can provide an item; commercial terms remain in the Supplier Catalog.')
                ->schema([
                    RepeatableEntry::make('activeProductSupportsPreview')
                        ->label('')
                        ->columns(4)
                        ->schema([
                            TextEntry::make('scope')
                                ->label('Scope')
                                ->state(fn (SupplierProductSupport $record): string => $record->product_variant_id === null
                                    ? 'Product-wide'
                                    : 'Variant-specific')
                                ->badge(),
                            TextEntry::make('product_name')
                                ->label('Product')
                                ->state(fn (SupplierProductSupport $record): string => self::capabilityProductName($record)),
                            TextEntry::make('variant')
                                ->label('Variant')
                                ->state(fn (SupplierProductSupport $record): string => self::capabilityVariantName($record))
                                ->placeholder('All variants'),
                            TextEntry::make('is_active')
                                ->label('Status')
                                ->state(fn (SupplierProductSupport $record): string => $record->is_active ? 'Active' : 'Inactive')
                                ->badge()
                                ->color(fn (SupplierProductSupport $record): string => $record->is_active ? 'success' : 'gray'),
                        ]),
                ]),

            Section::make('Commercial catalog')
                ->description('Showing up to 10 active commercial references used for Purchase Orders. Cost is the latest accepted purchase cost, not a payment price.')
                ->schema([
                    RepeatableEntry::make('activeProductReferencesPreview')
                        ->label('')
                        ->columns(7)
                        ->schema([
                            TextEntry::make('productVariant.product.name')->label('Internal product'),
                            TextEntry::make('productVariant.sku')->label('SKU'),
                            TextEntry::make('supplier_name')->label('Supplier product'),
                            TextEntry::make('supplier_item_number')->label('Supplier item number'),
                            TextEntry::make('purchase_cost')
                                ->label('Latest accepted cost')
                                ->money(static fn (SupplierProductReference $record): string => $record->currency_code),
                            TextEntry::make('currency_code')->label('Currency'),
                            TextEntry::make('is_active')
                                ->label('Status')
                                ->state(fn (SupplierProductReference $record): string => $record->is_active ? 'Active' : 'Inactive')
                                ->badge(),
                        ]),
                ]),

            Section::make('Purchasing activity')
                ->columns(4)
                ->schema([
                    TextEntry::make('open_po_count')
                        ->label('Open Purchase Orders')
                        ->state(fn (Supplier $record): int => $record->purchaseOrders()
                            ->whereNotIn('status', [
                                PurchaseOrderStatus::Received->value,
                                PurchaseOrderStatus::Closed->value,
                                PurchaseOrderStatus::Cancelled->value,
                            ])->count()),
                    TextEntry::make('awaiting_confirmation_count')
                        ->label('Awaiting supplier response')
                        ->state(fn (Supplier $record): int => $record->confirmations()
                            ->where('confirmation_status', 'pending')
                            ->whereHas('purchaseOrder', static fn (Builder $query): Builder => $query
                                ->whereNotNull('sent_at')
                                ->whereIn('status', [
                                    PurchaseOrderStatus::Accepted->value,
                                    PurchaseOrderStatus::PartiallyReceived->value,
                                ]))
                            ->count()),
                    TextEntry::make('active_catalog_count')
                        ->label('Active catalog items')
                        ->state(fn (Supplier $record): int => $record->productReferences()->where('is_active', true)->count()),
                    TextEntry::make('last_purchase')
                        ->label('Last purchase')
                        ->state(function (Supplier $record): string {
                            $value = $record->purchaseOrders()->max('ordered_at');

                            return is_string($value) ? $value : '—';
                        }),
                    RepeatableEntry::make('recentPurchaseOrders')
                        ->label('Recent Purchase Orders')
                        ->columns(6)
                        ->columnSpanFull()
                        ->schema([
                            TextEntry::make('purchase_order_number')
                                ->label('PO')
                                ->url(fn (PurchaseOrder $record): string => PurchaseOrderResource::getUrl('view', ['record' => $record]))
                                ->color('primary'),
                            TextEntry::make('status')->label('Status')->badge(),
                            TextEntry::make('total_amount')
                                ->label('Total')
                                ->money(static fn (PurchaseOrder $record): string => $record->currency_code),
                            TextEntry::make('ordered_at')->label('Ordered')->date(),
                            TextEntry::make('expected_at')->label('Expected')->date()->placeholder('—'),
                            TextEntry::make('received_progress')
                                ->label('Received')
                                ->state(fn (PurchaseOrder $record): string => QuantityFormatter::display($record->lines->sum('received_base_quantity'))
                                    .' / '.QuantityFormatter::display($record->lines->sum('base_quantity'))),
                        ]),
                ]),

            Section::make('Accounting visibility')
                ->description('Read-only supplier payable context. Accounting owns Bill approval and Supplier Payments.')
                ->visible(fn (): bool => auth()->user()?->can('viewAny', Bill::class) ?? false)
                ->columns(4)
                ->schema([
                    TextEntry::make('open_payable')
                        ->label('Open payable documents')
                        ->state(fn (Supplier $record): int => $record->bills()
                            ->whereIn('status', [BillStatus::Approved->value, BillStatus::PartiallyPaid->value])
                            ->count())
                        ->helperText('Amounts remain on their individual Accounting documents; unlike currencies are never summed here.'),
                    TextEntry::make('bill_count')
                        ->label('Bills')
                        ->state(fn (Supplier $record): int => $record->bills()->count()),
                    TextEntry::make('payment_count')
                        ->label('Supplier payments')
                        ->state(fn (Supplier $record): int => $record->supplierPayments()->count()),
                    RepeatableEntry::make('recentBills')
                        ->label('Recent Bills')
                        ->columns(6)
                        ->columnSpanFull()
                        ->schema([
                            TextEntry::make('bill_number')
                                ->label('Bill')
                                ->url(fn (Bill $record): string => BillResource::getUrl('view', ['record' => $record]))
                                ->color('primary'),
                            TextEntry::make('status')->label('Status')->badge(),
                            TextEntry::make('supplier_reference')
                                ->label('Supplier invoice reference')
                                ->formatStateUsing(static fn (mixed $state): string => is_string($state)
                                    ? (str_starts_with($state, 'PO-AUTO:') ? 'Awaiting supplier invoice' : $state)
                                    : '—'),
                            TextEntry::make('grand_total')->label('Total')->money(),
                            TextEntry::make('paid_amount')->label('Paid')->money(),
                            TextEntry::make('due_date')->label('Due')->date()->placeholder('—'),
                        ]),
                ]),
        ]);
    }

    private static function capabilityProductName(SupplierProductSupport $support): string
    {
        $product = $support->product;

        if ($product instanceof Product) {
            return $product->name;
        }

        $variant = $support->productVariant;

        if ($variant instanceof ProductVariant && $variant->product instanceof Product) {
            return $variant->product->name;
        }

        return '—';
    }

    private static function capabilityVariantName(SupplierProductSupport $support): string
    {
        if ($support->product_variant_id === null) {
            return 'All variants';
        }

        $variant = $support->productVariant;

        return $variant instanceof ProductVariant ? $variant->sku : '—';
    }
}
