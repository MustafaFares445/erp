<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders\Schemas;

use App\Enums\PurchaseOrderDocument;
use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\SupplierPayments\SupplierPaymentResource;
use App\Models\Bill;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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
            Section::make(__('admin.purchasing.sections.documents'))
                ->columns(2)
                ->schema([
                    ...array_map(self::documentUploadEntry(...), PurchaseOrderDocument::cases()),
                    TextEntry::make('bill')
                        ->label(__('admin.purchasing.fields.supplier_invoice'))
                        ->state(function (PurchaseOrder $record): string {
                            $bill = self::latestBill($record);

                            return $bill instanceof Bill ? $bill->bill_number : __('admin.purchasing.documents.no_bill');
                        })
                        ->url(function (PurchaseOrder $record): ?string {
                            $bill = self::latestBill($record);

                            return $bill instanceof Bill ? BillResource::getUrl('view', ['record' => $bill]) : null;
                        })
                        ->color(fn (PurchaseOrder $record): string => self::latestBill($record) instanceof Bill ? 'success' : 'warning'),
                    TextEntry::make('supplier_payment')
                        ->label(__('admin.purchasing.fields.supplier_payment'))
                        ->state(function (PurchaseOrder $record): string {
                            $payment = self::latestSupplierPayment($record);

                            return $payment instanceof SupplierPayment ? $payment->supplier_payment_number : __('admin.purchasing.documents.no_payment');
                        })
                        ->url(function (PurchaseOrder $record): ?string {
                            $payment = self::latestSupplierPayment($record);

                            return $payment instanceof SupplierPayment ? SupplierPaymentResource::getUrl('edit', ['record' => $payment]) : null;
                        })
                        ->color(fn (PurchaseOrder $record): string => self::latestSupplierPayment($record) instanceof SupplierPayment ? 'success' : 'warning'),
                ]),
        ]);
    }

    private static function documentUploadEntry(PurchaseOrderDocument $document): TextEntry
    {
        return TextEntry::make($document->value)
            ->label($document->label())
            ->state(function (PurchaseOrder $record) use ($document): string {
                $media = $record->getFirstMedia($document->value);

                return $media instanceof Media ? $media->file_name : __('admin.purchasing.documents.missing');
            })
            ->url(fn (PurchaseOrder $record): ?string => self::mediaRoute($record, $record->getFirstMedia($document->value), 'preview'))
            ->openUrlInNewTab()
            ->suffixAction(
                Action::make('download_'.$document->value)
                    ->label(__('admin.purchasing.documents.download'))
                    ->icon(Heroicon::ArrowDownTray)
                    ->url(fn (PurchaseOrder $record): ?string => self::mediaRoute($record, $record->getFirstMedia($document->value), 'download'))
                    ->openUrlInNewTab()
                    ->visible(fn (PurchaseOrder $record): bool => $record->getFirstMedia($document->value) instanceof Media),
            )
            ->color(fn (PurchaseOrder $record): string => $record->getFirstMedia($document->value) instanceof Media ? 'success' : 'warning');
    }

    private static function mediaRoute(PurchaseOrder $record, ?Media $media, string $action): ?string
    {
        return $media instanceof Media
            ? route('admin.purchase-orders.media.'.$action, ['purchaseOrder' => $record, 'media' => $media])
            : null;
    }

    private static function latestBill(PurchaseOrder $record): ?Bill
    {
        return $record->bills()->latest('id')->first();
    }

    private static function latestSupplierPayment(PurchaseOrder $record): ?SupplierPayment
    {
        $bill = self::latestBill($record);

        if (! $bill instanceof Bill) {
            return null;
        }

        $allocation = $bill->paymentAllocations()->latest('id')->first();

        return $allocation instanceof SupplierPaymentAllocation ? $allocation->supplierPayment : null;
    }
}
