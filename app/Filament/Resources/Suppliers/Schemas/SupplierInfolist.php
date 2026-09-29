<?php

declare(strict_types=1);

namespace App\Filament\Resources\Suppliers\Schemas;

use App\Enums\BillStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierConfirmationStatus;
use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\Bill;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierConfirmation;
use App\Models\SupplierProductReference;
use App\Services\Purchasing\PurchaseOrderWorkflowService;
use App\Support\QuantityFormatter;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class SupplierInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Supplier profile')
                ->description('Commercial supplier identity and default procurement policy.')
                ->columnSpanFull()
                ->columns(4)
                ->schema([
                    ImageEntry::make('logo_path')->label('Logo')->disk('public')->circular()->height(64),
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

            Section::make('Products supplied')
                ->description('One place to see what this supplier can provide and the commercial details Purchasing uses.')
                ->columnSpanFull()
                ->schema([
                    RepeatableEntry::make('productReferences')
                        ->hiddenLabel()
                        ->columns(6)
                        ->schema([
                            ImageEntry::make('product_image')
                                ->hiddenLabel()
                                ->state(fn (SupplierProductReference $record): ?string => $record->productVariant?->mainImageUrl())
                                ->height(42)
                                ->square(),
                            TextEntry::make('productVariant.product.name')
                                ->label('Product')
                                ->helperText(fn (SupplierProductReference $record): string => self::supplierProductVariantSummary($record)),
                            TextEntry::make('supplier_item_number')->label('Supplier item')->placeholder('Not configured'),
                            TextEntry::make('purchase_cost')
                                ->label('Reference cost')
                                ->money(static fn (SupplierProductReference $record): string => $record->currency_code)
                                ->placeholder('Not configured'),
                            TextEntry::make('lead_time_days')->label('Lead time')->suffix(' days')->placeholder('—'),
                            TextEntry::make('availability_status')
                                ->label('Availability')
                                ->formatStateUsing(static fn (string $state): string => match ($state) {
                                    'temporarily_unavailable' => 'Temporarily unavailable',
                                    'discontinued' => 'Discontinued',
                                    default => 'Active',
                                })
                                ->badge()
                                ->color(static fn (string $state): string => match ($state) {
                                    'active' => 'success',
                                    'temporarily_unavailable' => 'warning',
                                    default => 'gray',
                                }),
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
                        ->label('Active supplier products')
                        ->state(fn (Supplier $record): int => $record->productReferences()->where('is_active', true)->count()),
                    TextEntry::make('last_purchase')
                        ->label('Last purchase')
                        ->state(function (Supplier $record): string {
                            $value = $record->purchaseOrders()->max('ordered_at');

                            return is_string($value) ? $value : '—';
                        }),
                    RepeatableEntry::make('purchaseOrders')
                        ->label('Purchase Orders')
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

            Section::make('Supplier performance')
                ->description('Operational performance derived from recorded supplier responses and completed Purchase Orders.')
                ->columns(4)
                ->schema([
                    TextEntry::make('answered_confirmation_count')
                        ->label('Responses recorded')
                        ->state(fn (Supplier $record): int => $record->confirmations()
                            ->where('confirmation_status', '!=', SupplierConfirmationStatus::Pending->value)
                            ->count())
                        ->helperText('Supplier confirmations that have received a recorded response.'),
                    TextEntry::make('backorder_response_count')
                        ->label('Backordered responses')
                        ->state(fn (Supplier $record): int => $record->confirmations()
                            ->whereHas('items', static fn (Builder $query): Builder => $query
                                ->where('backordered_base_quantity', '>', 0))
                            ->count())
                        ->helperText('Responses where at least one requested line remains backordered.'),
                    TextEntry::make('rejected_response_count')
                        ->label('Rejected responses')
                        ->state(fn (Supplier $record): int => $record->confirmations()
                            ->where(function (Builder $query): void {
                                $query->where('confirmation_status', SupplierConfirmationStatus::Rejected->value)
                                    ->orWhereHas('items', static fn (Builder $items): Builder => $items
                                        ->where('confirmation_status', SupplierConfirmationStatus::Rejected->value));
                            })
                            ->count())
                        ->helperText('Supplier responses that rejected all or part of a requested commitment.'),
                    TextEntry::make('on_time_receipt_rate')
                        ->label('On-time receipt')
                        ->state(fn (Supplier $record): string => self::onTimeReceiptSummary($record))
                        ->helperText('Compares the final physical receipt date with the Purchase Order expected date.'),
                    TextEntry::make('open_backorder_po_count')
                        ->label('POs needing backorder follow-up')
                        ->state(fn (Supplier $record): int => self::openBackorderOrderCount($record))
                        ->helperText('Active sent Purchase Orders with supplier-backed quantity still awaiting a later commitment.'),
                    TextEntry::make('average_response_time')
                        ->label('Average response time')
                        ->state(fn (Supplier $record): string => self::averageResponseTime($record))
                        ->helperText('Average time from each supplier confirmation request being created until its response is recorded.'),
                    TextEntry::make('average_receipt_lead_time')
                        ->label('Average receipt lead time')
                        ->state(fn (Supplier $record): string => self::averageReceiptLeadTime($record))
                        ->helperText('Average time from PO order date to the final completed physical receipt.'),
                    TextEntry::make('committed_value_by_currency')
                        ->label('Sent PO value by currency')
                        ->state(fn (Supplier $record): string => self::committedValueByCurrency($record))
                        ->helperText('Values remain separated by currency; unlike currencies are never summed.'),
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
                            TextEntry::make('due_date')->label('Due')->date()->placeholder('—'),
                        ]),
                ]),
        ]);
    }

    private static function openBackorderOrderCount(Supplier $supplier): int
    {
        $orders = $supplier->purchaseOrders()
            ->whereNotNull('sent_at')
            ->whereIn('status', [
                PurchaseOrderStatus::Accepted->value,
                PurchaseOrderStatus::PartiallyReceived->value,
            ])
            ->get();

        $count = 0;

        foreach ($orders as $order) {
            $workflow = app(PurchaseOrderWorkflowService::class)->project($order);

            if (is_numeric($workflow->backorderedBaseQuantity)
                && (float) $workflow->backorderedBaseQuantity > 0.000001) {
                $count++;
            }
        }

        return $count;
    }

    private static function averageResponseTime(Supplier $supplier): string
    {
        $confirmations = $supplier->confirmations()
            ->whereNotNull('confirmed_at')
            ->get(['created_at', 'confirmed_at']);

        if ($confirmations->isEmpty()) {
            return 'No responses recorded';
        }

        $minutes = $confirmations
            ->map(static function (SupplierConfirmation $confirmation): float {
                $createdAt = Carbon::parse($confirmation->created_at);
                $confirmedAt = Carbon::parse($confirmation->confirmed_at);

                return $createdAt->diffInMinutes($confirmedAt);
            })
            ->average();

        return self::durationSummary((float) $minutes);
    }

    private static function averageReceiptLeadTime(Supplier $supplier): string
    {
        $orders = $supplier->purchaseOrders()
            ->where('status', PurchaseOrderStatus::Received->value)
            ->withMax('receipts as last_receipt_completed_at', 'completed_at')
            ->get(['id', 'ordered_at']);

        $minutes = [];

        foreach ($orders as $order) {
            $completedAt = $order->getAttribute('last_receipt_completed_at');
            if (! $order->ordered_at instanceof Carbon) {
                continue;
            }
            if (! is_string($completedAt)) {
                continue;
            }
            if ($completedAt === '') {
                continue;
            }

            $minutes[] = (float) $order->ordered_at->startOfDay()->diffInMinutes(Carbon::parse($completedAt));
        }

        if ($minutes === []) {
            return 'No completed receipt history';
        }

        return self::durationSummary(array_sum($minutes) / count($minutes));
    }

    private static function committedValueByCurrency(Supplier $supplier): string
    {
        $totals = $supplier->purchaseOrders()
            ->whereNotNull('sent_at')
            ->where('status', '!=', PurchaseOrderStatus::Cancelled->value)
            ->selectRaw('currency_code, SUM(total_amount) as aggregate_total')
            ->groupBy('currency_code')
            ->orderBy('currency_code')
            ->get();

        if ($totals->isEmpty()) {
            return 'No sent Purchase Orders';
        }

        return $totals
            ->map(static function (PurchaseOrder $order): string {
                $aggregate = $order->getAttribute('aggregate_total');
                $amount = is_numeric($aggregate) ? (float) $aggregate : 0.0;

                return sprintf(
                    '%s %s',
                    $order->currency_code,
                    number_format($amount, 2, '.', ','),
                );
            })
            ->implode(' · ');
    }

    private static function supplierProductVariantSummary(SupplierProductReference $reference): string
    {
        $variant = $reference->productVariant;

        if ($variant === null) {
            return 'Variant unavailable';
        }

        return mb_trim($variant->name.' · '.$variant->sku, ' ·');
    }

    private static function durationSummary(float $minutes): string
    {
        if ($minutes < 60) {
            return sprintf('%.0f min', $minutes);
        }

        if ($minutes < 1440) {
            return sprintf('%.1f hours', $minutes / 60);
        }

        return sprintf('%.1f days', $minutes / 1440);
    }

    private static function onTimeReceiptSummary(Supplier $supplier): string
    {
        $orders = $supplier->purchaseOrders()
            ->where('status', PurchaseOrderStatus::Received->value)
            ->whereNotNull('expected_at')
            ->withMax('receipts as last_receipt_completed_at', 'completed_at')
            ->get(['id', 'expected_at']);

        $eligible = 0;
        $onTime = 0;

        foreach ($orders as $order) {
            $completedAt = $order->getAttribute('last_receipt_completed_at');
            if (! is_string($completedAt)) {
                continue;
            }
            if ($completedAt === '') {
                continue;
            }

            $expectedAt = $order->expected_at;

            if (! $expectedAt instanceof Carbon) {
                continue;
            }

            $eligible++;

            if (Carbon::parse($completedAt)->lte($expectedAt->endOfDay())) {
                $onTime++;
            }
        }

        return $eligible === 0
            ? 'No completed POs with expected dates'
            : sprintf('%d / %d on time', $onTime, $eligible);
    }
}
