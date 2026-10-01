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
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use WeakMap;

final class PurchaseOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Workflow'))
                ->description(__('Follow the Purchase Order from creation through supplier, receiving, accounting, and completion.'))
                ->schema([
                    ViewEntry::make('workflow_stepper')
                        ->hiddenLabel()
                        ->view('filament.purchasing.purchase-order-workflow-stepper')
                        ->viewData(fn (PurchaseOrder $record): array => [
                            'steps' => self::workflowSteps($record),
                        ])
                        ->columnSpanFull(),
                ]),

            Section::make(__('Action required'))
                ->description(fn (PurchaseOrder $record): string => self::projection($record)->nextOwner === 'None'
                    ? 'No action is currently required for this Purchase Order.'
                    : 'What needs to happen next and who owns it.')
                ->columns(2)
                ->schema([
                    TextEntry::make('workflow_state')
                        ->label(__('Current stage'))
                        ->state(fn (PurchaseOrder $record): string => self::projection($record)->businessState)
                        ->badge()
                        ->color(fn (PurchaseOrder $record): string => self::projection($record)->blocker === null ? 'info' : 'warning'),
                    TextEntry::make('workflow_owner')
                        ->label(__('Next owner'))
                        ->state(fn (PurchaseOrder $record): string => self::projection($record)->nextOwner)
                        ->badge(),
                    TextEntry::make('workflow_blocker')
                        ->label(__('Attention'))
                        ->state(fn (PurchaseOrder $record): string => self::projection($record)->blocker === null ? 'No blocker' : self::projection($record)->nextOwner.' attention required')
                        ->helperText(fn (PurchaseOrder $record): ?string => self::projection($record)->blocker)
                        ->badge()
                        ->color(fn (PurchaseOrder $record): string => self::projection($record)->blocker === null ? 'success' : 'warning')
                        ->columnSpanFull(),
                    TextEntry::make('workflow_action')
                        ->label(__('Next action'))
                        ->state(fn (PurchaseOrder $record): string => self::projection($record)->nextAction)
                        ->weight(FontWeight::Bold)
                        ->columnSpanFull(),
                ]),

            Section::make(__('Order summary'))
                ->columns(4)
                ->schema([
                    ImageEntry::make('supplier.logo_path')->hiddenLabel()->disk('public')->circular()->height(52),
                    TextEntry::make('supplier.name')
                        ->label(__('Supplier'))
                        ->helperText(fn (PurchaseOrder $record): string => $record->supplier->code),
                    TextEntry::make('status')
                        ->label(__('Commercial status'))
                        ->badge()
                        ->formatStateUsing(static fn (PurchaseOrderStatus $state): string => $state->label()),
                    TextEntry::make('total_amount')
                        ->label(__('Total'))
                        ->money(static fn (PurchaseOrder $record): string => $record->currency_code),
                    TextEntry::make('ordered_at')->label(__('Ordered'))->date(),
                    TextEntry::make('expected_at')->label(__('Expected'))->date()->placeholder(__('Not specified')),
                    TextEntry::make('sent_at')->label(__('Last sent'))->dateTime()->placeholder(__('Not sent')),
                    TextEntry::make('purchase_order_number')->label(__('PO number')),
                    TextEntry::make('rejection_reason')
                        ->label(__('Returned for revision'))
                        ->visible(fn (PurchaseOrder $record): bool => filled($record->rejection_reason))
                        ->columnSpanFull(),
                    TextEntry::make('notes')->label(__('Notes'))->placeholder(__('No notes'))->columnSpanFull(),
                ]),

            Section::make(__('Fulfillment progress'))
                ->description(__('Ordered → supplier confirmed → warehouse allocated → physically received.'))
                ->columns(4)
                ->schema([
                    TextEntry::make('ordered_qty')
                        ->label(__('Ordered'))
                        ->state(fn (PurchaseOrder $record): string => QuantityFormatter::display(self::projection($record)->orderedBaseQuantity)),
                    TextEntry::make('confirmed_qty')
                        ->label(__('Confirmed'))
                        ->state(fn (PurchaseOrder $record): string => QuantityFormatter::display(self::projection($record)->confirmedBaseQuantity)),
                    TextEntry::make('allocated_qty')
                        ->label(__('Allocated'))
                        ->state(fn (PurchaseOrder $record): string => QuantityFormatter::display(self::projection($record)->allocatedBaseQuantity)),
                    TextEntry::make('received_qty')
                        ->label(__('Received'))
                        ->state(fn (PurchaseOrder $record): string => QuantityFormatter::display(self::projection($record)->receivedBaseQuantity)),
                    TextEntry::make('backordered_qty')
                        ->label(__('Backordered'))
                        ->visible(fn (PurchaseOrder $record): bool => (float) self::projection($record)->backorderedBaseQuantity > 0.000001)
                        ->state(fn (PurchaseOrder $record): string => QuantityFormatter::display(self::projection($record)->backorderedBaseQuantity))
                        ->badge()
                        ->color('warning'),
                    TextEntry::make('unavailable_qty')
                        ->label(__('Supplier unavailable'))
                        ->visible(fn (PurchaseOrder $record): bool => (float) self::projection($record)->unavailableBaseQuantity > 0.000001)
                        ->state(fn (PurchaseOrder $record): string => QuantityFormatter::display(self::projection($record)->unavailableBaseQuantity))
                        ->badge()
                        ->color('danger'),
                    TextEntry::make('receipt_in_progress_qty')
                        ->label(__('Receipt in progress'))
                        ->visible(fn (PurchaseOrder $record): bool => (float) self::projection($record)->receiptInProgressBaseQuantity > 0.000001)
                        ->state(fn (PurchaseOrder $record): string => QuantityFormatter::display(self::projection($record)->receiptInProgressBaseQuantity))
                        ->badge()
                        ->color('info'),
                    TextEntry::make('remaining_confirmed_qty')
                        ->label(__('Remaining to receive'))
                        ->visible(fn (PurchaseOrder $record): bool => (float) self::projection($record)->remainingConfirmedBaseQuantity > 0.000001)
                        ->state(fn (PurchaseOrder $record): string => QuantityFormatter::display(self::projection($record)->remainingConfirmedBaseQuantity))
                        ->badge()
                        ->color('warning'),
                ]),

            Section::make(__('Products'))
                ->description(__('Items, quantities, receiving progress, and agreed cost.'))
                ->schema([
                    RepeatableEntry::make('lines')
                        ->label('')
                        ->columns(7)
                        ->schema([
                            ImageEntry::make('product_image')
                                ->label('')
                                ->state(fn (PurchaseOrderLine $record): ?string => $record->productVariant->mainImageUrl())
                                ->height(48)
                                ->square(),
                            TextEntry::make('productVariant.product.name')
                                ->label(__('Product'))
                                ->helperText(fn (PurchaseOrderLine $record): string => mb_trim($record->productVariant->name.' · '.$record->productVariant->sku, ' ·')),
                            TextEntry::make('supplier_item_number')->label(__('Supplier item'))->placeholder(__('Not configured')),
                            TextEntry::make('unit.name')->label(__('Unit')),
                            TextEntry::make('receiving')
                                ->label(__('Receiving'))
                                ->state(fn (PurchaseOrderLine $record): string => QuantityFormatter::display($record->quantity_received).' / '.QuantityFormatter::display($record->quantity_ordered)),
                            TextEntry::make('unit_cost')
                                ->label(__('Unit cost'))
                                ->money(static fn (PurchaseOrderLine $record): string => $record->purchaseOrder->currency_code),
                            TextEntry::make('line_total')
                                ->label(__('Line total'))
                                ->money(static fn (PurchaseOrderLine $record): string => $record->purchaseOrder->currency_code),
                        ]),
                ]),

            Section::make(__('Supplier response'))
                ->description(fn (PurchaseOrder $record): string => self::confirmationRequired($record)
                    ? 'Supplier promises, backorders, and delivery dates for this Purchase Order.'
                    : 'Supplier confirmation is not required for this Purchase Order.')
                ->schema([
                    TextEntry::make('confirmation_policy')
                        ->label(__('Confirmation'))
                        ->state(fn (PurchaseOrder $record): string => self::confirmationRequired($record) ? 'Required' : 'Not required')
                        ->badge()
                        ->color(fn (PurchaseOrder $record): string => self::confirmationRequired($record) ? 'warning' : 'gray'),
                    RepeatableEntry::make('confirmations')
                        ->label(__('Response history'))
                        ->visible(fn (PurchaseOrder $record): bool => $record->confirmations->isNotEmpty())
                        ->columns(4)
                        ->schema([
                            TextEntry::make('confirmation_status')->label(__('Response'))->badge(),
                            TextEntry::make('promised_at')->label(__('Promised date'))->date()->placeholder(__('—')),
                            TextEntry::make('confirmedBy.name')->label(__('Recorded by'))->placeholder(__('—')),
                            TextEntry::make('notes')->label(__('Notes'))->placeholder(__('No notes'))->wrap(),
                        ]),
                ]),

            Section::make(__('Receiving'))
                ->description(__('Warehouse allocation and physical receipt progress.'))
                ->columns(3)
                ->schema([
                    TextEntry::make('purchase_inbound')
                        ->label(__('Purchase inbound'))
                        ->state(fn (PurchaseOrder $record): string => $record->purchaseInbound === null ? 'Not activated' : 'INB-'.$record->purchaseInbound->id)
                        ->url(fn (PurchaseOrder $record): ?string => $record->purchaseInbound === null
                            ? null
                            : PurchaseInboundResource::getUrl('view', ['record' => $record->purchaseInbound]))
                        ->color(fn (PurchaseOrder $record): string => $record->purchaseInbound === null ? 'warning' : 'primary'),
                    TextEntry::make('logistics_business_state')
                        ->label(__('Inbound state'))
                        ->state(fn (PurchaseOrder $record): string => self::projection($record)->logisticsState)
                        ->badge(),
                    TextEntry::make('receipt_summary')
                        ->label(__('Receipts'))
                        ->state(fn (PurchaseOrder $record): string => $record->receipts->count().' receipt(s)'),
                    RepeatableEntry::make('receipts')
                        ->label(__('Receipt history'))
                        ->columns(4)
                        ->columnSpanFull()
                        ->schema([
                            TextEntry::make('operation_number')->label(__('Receipt')),
                            TextEntry::make('stage')->label(__('Stage'))->badge(),
                            TextEntry::make('destinationWarehouse.name')->label(__('Warehouse'))->placeholder(__('—')),
                            TextEntry::make('completed_at')->label(__('Completed'))->dateTime()->placeholder(__('Open')),
                        ]),
                ]),

            Section::make(__('Accounting'))
                ->description(__('Supplier bill and payment status for this Purchase Order.'))
                ->visible(fn (): bool => auth()->user()?->can('viewAny', Bill::class) ?? false)
                ->columns(4)
                ->schema([
                    TextEntry::make('supplier_bill_missing')
                        ->label(__('Supplier bill'))
                        ->visible(fn (PurchaseOrder $record): bool => $record->bills->isEmpty())
                        ->state(__('Not created yet'))
                        ->badge()
                        ->color('warning')
                        ->helperText(fn (PurchaseOrder $record): string => 'PO value: '.$record->currency_code.' '.number_format((float) $record->total_amount, 2))
                        ->columnSpan(2),
                    TextEntry::make('accounting_state')
                        ->label(__('Financial state'))
                        ->state(fn (PurchaseOrder $record): string => self::projection($record)->financialState)
                        ->badge()
                        ->columnSpan(fn (PurchaseOrder $record): int => $record->bills->isEmpty() ? 2 : 1),
                    TextEntry::make('bill_total')
                        ->label(__('Billed'))
                        ->visible(fn (PurchaseOrder $record): bool => $record->bills->isNotEmpty())
                        ->state(fn (PurchaseOrder $record): string => self::projection($record)->billTotal)
                        ->money(static fn (PurchaseOrder $record): string => $record->currency_code),
                    TextEntry::make('paid_total')
                        ->label(__('Paid'))
                        ->visible(fn (PurchaseOrder $record): bool => $record->bills->isNotEmpty())
                        ->state(fn (PurchaseOrder $record): string => self::projection($record)->paidTotal)
                        ->money(static fn (PurchaseOrder $record): string => $record->currency_code),
                    TextEntry::make('outstanding_total')
                        ->label(__('Outstanding'))
                        ->visible(fn (PurchaseOrder $record): bool => $record->bills->isNotEmpty())
                        ->state(fn (PurchaseOrder $record): string => self::projection($record)->outstandingTotal)
                        ->money(static fn (PurchaseOrder $record): string => $record->currency_code),
                    RepeatableEntry::make('bills')
                        ->label(__('Supplier bills'))
                        ->visible(fn (PurchaseOrder $record): bool => $record->bills->isNotEmpty())
                        ->columns(6)
                        ->columnSpanFull()
                        ->schema([
                            TextEntry::make('bill_number')
                                ->label(__('Bill'))
                                ->url(fn (Bill $record): string => BillResource::getUrl('view', ['record' => $record]))
                                ->color('primary'),
                            TextEntry::make('status')->label(__('Status'))->badge(),
                            TextEntry::make('supplier_reference')
                                ->label(__('Supplier invoice'))
                                ->formatStateUsing(static fn (mixed $state): string => is_string($state)
                                    ? (str_starts_with($state, 'PO-AUTO:') ? 'Awaiting supplier invoice' : $state)
                                    : '—'),
                            TextEntry::make('grand_total')->label(__('Total'))->money(),
                            TextEntry::make('paid_amount')->label(__('Paid'))->money(),
                            TextEntry::make('outstanding')
                                ->label(__('Outstanding'))
                                ->state(static fn (Bill $record): string => number_format($record->outstandingAmount(), 2, '.', '')),
                        ]),
                    TextEntry::make('supplier_payments')
                        ->label(__('Supplier payments'))
                        ->visible(fn (PurchaseOrder $record): bool => $record->bills->isNotEmpty())
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
                        ->placeholder(__('No supplier payments yet')),
                ]),

            Section::make(__('Purchase documents'))
                ->description(__('Commercial files attached to the Purchase Order. Accounting artifacts are shown separately above.'))
                ->columns(2)
                ->schema(array_map(self::documentUploadEntry(...), PurchaseOrderDocument::cases())),
        ]);
    }

    /**
     * @return list<array{label:string,state:'done'|'current'|'pending'}>
     */
    private static function workflowSteps(PurchaseOrder $record): array
    {
        $projection = self::projection($record);
        $labels = ['Created', 'Approval', 'Sent', 'Supplier', 'Receiving', 'Accounting', 'Complete'];

        $currentIndex = match (true) {
            $record->status === PurchaseOrderStatus::Draft => 0,
            in_array($record->status, [PurchaseOrderStatus::PendingApproval, PurchaseOrderStatus::Rejected], true) => 1,
            $record->status === PurchaseOrderStatus::Cancelled => 6,
            $record->status === PurchaseOrderStatus::Closed => 6,
            $projection->nextOwner === 'None' => 6,
            $record->sent_at === null => 2,
            $projection->nextOwner === 'Purchasing' => 3,
            $projection->nextOwner === 'Inventory' => 4,
            $projection->nextOwner === 'Accounting' => 5,
            default => 4,
        };

        if ($record->status === PurchaseOrderStatus::Cancelled) {
            $labels[6] = 'Cancelled';
        } elseif ($record->status === PurchaseOrderStatus::Closed) {
            $labels[6] = 'Closed';
        }

        $steps = [];

        foreach ($labels as $index => $label) {
            $steps[] = [
                'label' => $label,
                'state' => $index < $currentIndex
                    ? 'done'
                    : ($index === $currentIndex ? 'current' : 'pending'),
            ];
        }

        return $steps;
    }

    private static function confirmationRequired(PurchaseOrder $record): bool
    {
        return $record->supplier_confirmation_required
            ?? (bool) $record->supplier->requires_confirmation;
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
