<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchasePermission;
use App\Filament\Resources\PurchaseInbounds\PurchaseInboundResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Widgets\Concerns\BuildsDashboardTables;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Models\PurchaseOrder;
use App\Services\Purchasing\PurchaseOrderWorkflowProjectionStore;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sent, still-receiving purchase orders by expected date, optionally for
 * one supplier; each row opens its inbound (or the PO when none exists).
 */
final class PurchasingUpcomingReceipts extends TableWidget
{
    protected static bool $isLazy = false;

    use BuildsDashboardTables;
    use InteractsWithDashboardFilters;

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(PurchasePermission::OrderView->value) ?? false;
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $this->dashboardTable($table)
            ->heading(__('dashboards.purchasing.tables.upcoming'))
            ->query(fn (): Builder => PurchaseOrder::query()
                ->with([
                    'supplier',
                    'lines.productVariant.unit',
                    'lines.purchaseInboundLine.allocations',
                    'purchaseInbound.lines.allocations.warehouse',
                    'purchaseInbound.lines.allocations.inventoryOperationLines.operation',
                    'purchaseInbound.lines.purchaseOrderLine.productVariant.product',
                    'purchaseInbound.lines.purchaseOrderLine.productVariant.unit',
                    'receipts.lines',
                    'confirmations.items',
                    'bills.paymentAllocations.supplierPayment',
                ])
                ->whereNotNull('sent_at')
                ->whereNotNull('expected_at')
                ->whereIn('status', [
                    PurchaseOrderStatus::Accepted->value,
                    PurchaseOrderStatus::PartiallyReceived->value,
                ])
                ->when($this->dashboardFilter('supplierId'), static fn (Builder $query, int $supplierId): Builder => $query->where('supplier_id', $supplierId))
                ->orderBy('expected_at'))
            ->columns([
                TextColumn::make('purchase_order_number')
                    ->label(__('dashboards.purchasing.columns.purchase_order'))
                    ->description(fn (PurchaseOrder $record): string => $record->supplier->name)
                    ->weight('medium'),
                TextColumn::make('expected_at')
                    ->label(__('dashboards.purchasing.columns.expected'))
                    ->date()
                    ->sinceTooltip()
                    ->color(fn (PurchaseOrder $record): string => $record->expected_at?->isPast() ? 'danger' : 'gray'),
                TextColumn::make('receiving_state')
                    ->label(__('dashboards.purchasing.columns.receiving'))
                    ->state(fn (PurchaseOrder $record): string => (string) __(app(PurchaseOrderWorkflowProjectionStore::class)->project($record)->logisticsState))
                    ->badge(),
                TextColumn::make('total_amount')
                    ->label(__('dashboards.purchasing.columns.value'))
                    ->money(fn (PurchaseOrder $record): string => $record->currency_code),
            ])
            ->recordUrl(fn (PurchaseOrder $record): string => $record->purchaseInbound !== null
                ? PurchaseInboundResource::getUrl('view', ['record' => $record->purchaseInbound])
                : PurchaseOrderResource::getUrl('view', ['record' => $record]));
    }
}
