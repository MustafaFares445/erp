<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders\Schemas;

use App\Data\Purchasing\PurchaseOrderWorkflowData;
use App\Enums\PurchaseOrderDocument;
use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\PurchaseInbounds\PurchaseInboundResource;
use App\Models\Bill;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SupplierPaymentAllocation;
use App\Services\Purchasing\PurchaseOrderWorkflowService;
use App\Support\QuantityFormatter;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use WeakMap;

final class PurchaseOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Current purchasing state')
                ->description('One business summary across Purchasing, Supplier, Inventory, and Accounting.')
                ->columns(4)
                ->schema([
                    TextEntry::make('workflow_state')
                        ->label('Current state')
                        ->state(fn (PurchaseOrder $record): string => self::projection($record)->businessState)
                        ->badge()
                        ->color('info'),
                    TextEntry::make('workflow_blocker')
                        ->label('Blocker')
                        ->state(fn (PurchaseOrder $record): ?string => self::projection($record)->blocker)
                        ->placeholder('No active blocker')
                        ->badge()
                        ->color(fn (PurchaseOrder $record): string => self::projection($record)->blocker === null ? 'success' : 'warning'),
                    TextEntry::make('workflow_owner')
                        ->label('Next owner')
                        ->state(fn (PurchaseOrder $record): string => self::projection($record)->nextOwner),
                    TextEntry::make('workflow_action')
                        ->label('Next action')
                        ->state(fn (PurchaseOrder $record): string => self::projection($record)->nextAction)
                        ->columnSpanFull(),
                    TextEntry::make('supplier_track')
                        ->label('Supplier track')
                        ->state(fn (PurchaseOrder $record): string => self::projection($record)->supplierState)
                        ->badge(),
                    TextEntry::make('logistics_track')
                        ->label('Logistics track')
                        ->state(fn (PurchaseOrder $record): string => self::projection($record)->logisticsState)
                        ->badge(),
                    TextEntry::make('accounting_track')
                        ->label('Accounting track')
                        ->state(fn (PurchaseOrder $record): string => self::projection($record)->financialState)
                        ->badge(),
                ]),

            Section::make('Commercial commitment')
                ->columns(4)
                ->schema([
                    TextEntry::make('purchase_order_number')->label(__('admin.purchasing.fields.purchase_order_number')),
                    TextEntry::make('supplier.name')->label(__('admin.purchasing.fields.supplier')),
                    TextEntry::make('status')
                        ->label('Commercial status')
                        ->badge()
                        ->formatStateUsing(static fn (PurchaseOrderStatus $state): string => $state->label()),
                    TextEntry::make('total_amount')
                        ->label(__('admin.purchasing.fields.total_amount'))
                        ->money(static fn (PurchaseOrder $record): string => $record->currency_code),
                    TextEntry::make('currency_code')->label(__('admin.purchasing.fields.currency_code')),
                    TextEntry::make('ordered_at')->label(__('admin.purchasing.fields.ordered_at'))->date(),
                    TextEntry::make('expected_at')->label(__('admin.purchasing.fields.expected_at'))->date()->placeholder('—'),
                    TextEntry::make('sent_at')->label('Last sent to supplier')->dateTime()->placeholder('Not sent'),
                    TextEntry::make('rejection_reason')
                        ->label('Returned for revision')
                        ->placeholder('—')
                        ->visible(fn (PurchaseOrder $record): bool => filled($record->rejection_reason))
                        ->columnSpanFull(),
                    TextEntry::make('notes')->label(__('admin.purchasing.fields.notes'))->placeholder('—')->columnSpanFull(),
                ]),

            Section::make('Quantity progress')
                ->description('Commercial demand versus supplier commitment and physical receiving.')
                ->columns(4)
                ->schema([
                    TextEntry::make('ordered_qty')
                        ->label('Ordered')
                        ->state(fn (PurchaseOrder $record): string => QuantityFormatter::display(self::projection($record)->orderedBaseQuantity)),
                    TextEntry::make('confirmed_qty')
                        ->label('Supplier confirmed')
                        ->state(fn (PurchaseOrder $record): string => QuantityFormatter::display(self::projection($record)->confirmedBaseQuantity)),
                    TextEntry::make('backordered_qty')
                        ->label('Backordered')
                        ->state(fn (PurchaseOrder $record): string => QuantityFormatter::display(self::projection($record)->backorderedBaseQuantity))
                        ->badge()
                        ->color(fn (PurchaseOrder $record): string => (float) self::projection($record)->backorderedBaseQuantity > 0 ? 'warning' : 'gray'),
                    TextEntry::make('unavailable_qty')
                        ->label('Supplier unavailable')
                        ->state(fn (PurchaseOrder $record): string => QuantityFormatter::display(self::projection($record)->unavailableBaseQuantity))
                        ->badge()
                        ->color(fn (PurchaseOrder $record): string => (float) self::projection($record)->unavailableBaseQuantity > 0 ? 'danger' : 'gray'),
                    TextEntry::make('allocated_qty')
                        ->label('Allocated to warehouses')
                        ->state(fn (PurchaseOrder $record): string => QuantityFormatter::display(self::projection($record)->allocatedBaseQuantity)),
                    TextEntry::make('receipt_in_progress_qty')
                        ->label('Receipt in progress')
                        ->state(fn (PurchaseOrder $record): string => QuantityFormatter::display(self::projection($record)->receiptInProgressBaseQuantity)),
                    TextEntry::make('received_qty')
                        ->label('Physically received')
                        ->state(fn (PurchaseOrder $record): string => QuantityFormatter::display(self::projection($record)->receivedBaseQuantity)),
                    TextEntry::make('remaining_confirmed_qty')
                        ->label('Confirmed remaining')
                        ->state(fn (PurchaseOrder $record): string => QuantityFormatter::display(self::projection($record)->remainingConfirmedBaseQuantity)),
                ]),

            Section::make(__('admin.purchasing.fields.lines'))
                ->schema([
                    RepeatableEntry::make('lines')
                        ->label('')
                        ->columns(8)
                        ->schema([
                            TextEntry::make('productVariant.product.name')->label(__('admin.purchasing.fields.product')),
                            TextEntry::make('productVariant.name')->label(__('admin.purchasing.fields.product_variant')),
                            TextEntry::make('supplier_item_number')->label(__('admin.purchasing.fields.supplier_item_number'))->placeholder('—'),
                            TextEntry::make('unit.name')->label(__('admin.purchasing.fields.unit')),
                            TextEntry::make('quantity_ordered')
                                ->label('Ordered')
                                ->formatStateUsing(static fn (mixed $state): string => QuantityFormatter::display($state)),
                            TextEntry::make('quantity_received')
                                ->label('Received')
                                ->formatStateUsing(static fn (mixed $state): string => QuantityFormatter::display($state)),
                            TextEntry::make('unit_cost')
                                ->label(__('admin.purchasing.fields.unit_cost'))
                                ->money(static fn (PurchaseOrderLine $record): string => $record->purchaseOrder->currency_code),
                            TextEntry::make('line_total')
                                ->label(__('admin.purchasing.fields.line_total'))
                                ->money(static fn (PurchaseOrderLine $record): string => $record->purchaseOrder->currency_code),
                        ]),
                ]),

            Section::make('Supplier commitment')
                ->description('Append-only evidence of supplier promises and exceptions.')
                ->schema([
                    TextEntry::make('confirmation_policy')
                        ->label('Confirmation policy at acceptance')
                        ->state(fn (PurchaseOrder $record): string => ($record->supplier_confirmation_required
                            ?? (bool) $record->supplier->requires_confirmation) ? 'Required' : 'Not required')
                        ->badge(),
                    RepeatableEntry::make('confirmations')
                        ->label('Confirmation history')
                        ->columns(4)
                        ->schema([
                            TextEntry::make('confirmation_status')->label('Response')->badge(),
                            TextEntry::make('promised_at')->label(__('admin.purchasing.fields.promised_at'))->date()->placeholder('—'),
                            TextEntry::make('confirmedBy.name')->label(__('admin.purchasing.fields.confirmed_by'))->placeholder('—'),
                            TextEntry::make('notes')->label(__('admin.purchasing.fields.notes'))->placeholder('—')->wrap(),
                        ]),
                ]),

            Section::make('Logistics visibility')
                ->description('Inventory owns warehouse allocation and receipt execution. Purchasing sees progress here as read-only context.')
                ->columns(3)
                ->schema([
                    TextEntry::make('purchase_inbound')
                        ->label('Purchase inbound')
                        ->state(fn (PurchaseOrder $record): string => $record->purchaseInbound === null ? 'Not activated' : 'INB-'.$record->purchaseInbound->id)
                        ->url(fn (PurchaseOrder $record): ?string => $record->purchaseInbound === null
                            ? null
                            : PurchaseInboundResource::getUrl('view', ['record' => $record->purchaseInbound]))
                        ->color(fn (PurchaseOrder $record): string => $record->purchaseInbound === null ? 'warning' : 'primary'),
                    TextEntry::make('logistics_business_state')
                        ->label('Inbound state')
                        ->state(fn (PurchaseOrder $record): string => self::projection($record)->logisticsState)
                        ->badge(),
                    TextEntry::make('receipt_summary')
                        ->label('Receipts')
                        ->state(fn (PurchaseOrder $record): string => $record->receipts->count().' receipt(s)'),
                    RepeatableEntry::make('receipts')
                        ->label('Receipt history')
                        ->columns(4)
                        ->columnSpanFull()
                        ->schema([
                            TextEntry::make('operation_number')->label('Receipt'),
                            TextEntry::make('stage')->label('Stage')->badge(),
                            TextEntry::make('destinationWarehouse.name')->label('Warehouse')->placeholder('—'),
                            TextEntry::make('completed_at')->label('Completed')->dateTime()->placeholder('Open'),
                        ]),
                ]),

            Section::make('Accounting visibility')
                ->description('Read-only payable context. Bill approval, payment, and journal posting remain Accounting-owned.')
                ->visible(fn (): bool => auth()->user()?->can('viewAny', Bill::class) ?? false)
                ->columns(4)
                ->schema([
                    TextEntry::make('bill_total')
                        ->label('Billed')
                        ->state(fn (PurchaseOrder $record): string => self::projection($record)->billTotal)
                        ->money(static fn (PurchaseOrder $record): string => $record->currency_code),
                    TextEntry::make('paid_total')
                        ->label('Paid')
                        ->state(fn (PurchaseOrder $record): string => self::projection($record)->paidTotal)
                        ->money(static fn (PurchaseOrder $record): string => $record->currency_code),
                    TextEntry::make('outstanding_total')
                        ->label('Outstanding')
                        ->state(fn (PurchaseOrder $record): string => self::projection($record)->outstandingTotal)
                        ->money(static fn (PurchaseOrder $record): string => $record->currency_code),
                    TextEntry::make('accounting_state')
                        ->label('Financial state')
                        ->state(fn (PurchaseOrder $record): string => self::projection($record)->financialState)
                        ->badge(),
                    RepeatableEntry::make('bills')
                        ->label('Bills')
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
                            TextEntry::make('outstanding')
                                ->label('Outstanding')
                                ->state(static fn (Bill $record): string => number_format($record->outstandingAmount(), 2, '.', '')),
                        ]),
                    TextEntry::make('supplier_payments')
                        ->label('Supplier payments')
                        ->columnSpanFull()
                        ->state(fn (PurchaseOrder $record): array => $record->bills
                            ->flatMap(fn (Bill $bill) => $bill->paymentAllocations)
                            ->map(fn (SupplierPaymentAllocation $allocation): ?string => $allocation->supplierPayment === null
                                ? null
                                : $allocation->supplierPayment->supplier_payment_number
                                    .' · '.number_format((float) $allocation->amount, 2)
                                    .' · '.$allocation->supplierPayment->status->label())
                            ->filter()
                            ->unique()
                            ->values()
                            ->all())
                        ->listWithLineBreaks()
                        ->placeholder('No supplier payments yet'),
                ]),

            Section::make('Purchase documents')
                ->description('Commercial files attached to the Purchase Order. Accounting artifacts are shown separately above.')
                ->columns(2)
                ->schema(array_map(self::documentUploadEntry(...), PurchaseOrderDocument::cases())),
        ]);
    }

    private static function projection(PurchaseOrder $record): PurchaseOrderWorkflowData
    {
        /** @var WeakMap<PurchaseOrder, PurchaseOrderWorkflowData>|null $cache */
        static $cache = null;

        $cache ??= new WeakMap;

        $cached = $cache[$record] ?? null;

        if ($cached instanceof PurchaseOrderWorkflowData) {
            return $cached;
        }

        $projection = app(PurchaseOrderWorkflowService::class)->project($record);
        $cache[$record] = $projection;

        return $projection;
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
}
