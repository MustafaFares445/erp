<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Data\Purchasing\PurchaseOrderWorkflowData;
use App\Enums\BillStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchasePermission;
use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\PurchaseInbounds\PurchaseInboundResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\SupplierConfirmations\SupplierConfirmationResource;
use App\Filament\Widgets\Concerns\BuildsDashboardTables;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Models\Bill;
use App\Models\PurchaseOrder;
use App\Models\SupplierConfirmation;
use App\Services\Purchasing\PurchaseOrderWorkflowService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use WeakMap;

/**
 * Open purchase orders someone has to act on — approval, sending, supplier
 * response, overdue delivery, receiving or billing — soonest-expected
 * first, optionally for one supplier. A current-state work queue, so it
 * ignores the date range.
 */
final class PurchasingAttentionQueue extends TableWidget
{
    use BuildsDashboardTables;
    use InteractsWithDashboardFilters;

    /** @var WeakMap<PurchaseOrder, PurchaseOrderWorkflowData>|null */
    private static ?WeakMap $projections = null;

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(PurchasePermission::OrderView->value) ?? false;
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $this->dashboardTable($table)
            ->heading(__('dashboards.purchasing.tables.attention'))
            ->query(fn (): Builder => self::query()
                ->when($this->dashboardFilter('supplierId'), static fn (Builder $query, int $supplierId): Builder => $query->where('supplier_id', $supplierId)))
            ->defaultSort('expected_at', 'asc')
            ->columns([
                TextColumn::make('purchase_order_number')
                    ->label(__('dashboards.purchasing.columns.purchase_order'))
                    ->description(fn (PurchaseOrder $record): string => $record->supplier->name)
                    ->weight('medium'),
                TextColumn::make('attention')
                    ->label(__('dashboards.purchasing.columns.reason'))
                    ->state(fn (PurchaseOrder $record): string => self::attentionReason($record))
                    ->badge()
                    ->color(fn (PurchaseOrder $record): string => self::attentionColor($record)),
                TextColumn::make('expected_at')
                    ->label(__('dashboards.purchasing.columns.expected'))
                    ->date()
                    ->placeholder(__('dashboards.purchasing.columns.not_specified'))
                    ->color(fn (PurchaseOrder $record): string => self::isOverdue($record) ? 'danger' : 'gray'),
                TextColumn::make('action')
                    ->label(__('dashboards.purchasing.columns.next_action'))
                    ->state(fn (PurchaseOrder $record): string => self::projection($record)->nextAction)
                    ->description(fn (PurchaseOrder $record): string => self::projection($record)->nextOwner)
                    ->color('primary'),
            ])
            ->recordUrl(fn (PurchaseOrder $record): string => self::actionUrl($record));
    }

    /** @return Builder<PurchaseOrder> */
    private static function query(): Builder
    {
        return PurchaseOrder::query()
            ->with([
                'supplier',
                'purchaseInbound.lines.allocations.warehouse',
                'lines.purchaseInboundLine.allocations',
                'receipts.lines',
                'confirmations.items',
                'bills.paymentAllocations.supplierPayment',
            ])
            ->whereNotIn('status', [
                PurchaseOrderStatus::Closed->value,
                PurchaseOrderStatus::Cancelled->value,
            ])
            ->where(function (Builder $query): void {
                $query->where('status', PurchaseOrderStatus::PendingApproval->value)
                    ->orWhere(function (Builder $query): void {
                        $query->where('status', PurchaseOrderStatus::Accepted->value)
                            ->whereNull('sent_at');
                    })
                    ->orWhereHas('confirmations', static fn (Builder $query): Builder => $query->where('confirmation_status', 'pending'))
                    ->orWhere(function (Builder $query): void {
                        $query->whereDate('expected_at', '<', today())
                            ->whereNotIn('status', [
                                PurchaseOrderStatus::Received->value,
                                PurchaseOrderStatus::Closed->value,
                                PurchaseOrderStatus::Cancelled->value,
                            ]);
                    })
                    ->orWhere('status', PurchaseOrderStatus::PartiallyReceived->value)
                    ->orWhere(function (Builder $query): void {
                        $query->where('status', PurchaseOrderStatus::Received->value)
                            ->where(function (Builder $accounting): void {
                                $accounting->whereDoesntHave('bills')
                                    ->orWhereHas('bills', static fn (Builder $bills): Builder => $bills->where('status', BillStatus::Draft->value));
                            });
                    });
            });
    }

    /**
     * The workflow projection is needed by several columns and the row URL;
     * build it once per loaded row.
     */
    private static function projection(PurchaseOrder $record): PurchaseOrderWorkflowData
    {
        self::$projections ??= new WeakMap;

        return self::$projections[$record] ??= app(PurchaseOrderWorkflowService::class)->project($record);
    }

    private static function attentionReason(PurchaseOrder $record): string
    {
        if (self::isOverdue($record)) {
            return __('dashboards.purchasing.attention.overdue');
        }

        $projection = self::projection($record);

        return $projection->blocker ?? $projection->businessState;
    }

    private static function attentionColor(PurchaseOrder $record): string
    {
        if (self::isOverdue($record)) {
            return 'danger';
        }

        return self::projection($record)->blocker === null
            ? 'info'
            : 'warning';
    }

    private static function isOverdue(PurchaseOrder $record): bool
    {
        return $record->expected_at !== null
            && $record->expected_at->isPast()
            && ! $record->status->isTerminal();
    }

    private static function actionUrl(PurchaseOrder $record): string
    {
        $projection = self::projection($record);

        if ($projection->nextOwner === 'Inventory' && $record->purchaseInbound !== null) {
            return PurchaseInboundResource::getUrl('view', ['record' => $record->purchaseInbound]);
        }

        if ($projection->nextOwner === 'Accounting') {
            $bill = $record->bills->sortByDesc('id')->first();

            if ($bill instanceof Bill) {
                return BillResource::getUrl('view', ['record' => $bill]);
            }
        }

        if ($projection->nextOwner === 'Purchasing') {
            $confirmation = $record->confirmations
                ->sortByDesc('id')
                ->first(fn (SupplierConfirmation $confirmation): bool => $confirmation->confirmation_status->value === 'pending');

            if ($confirmation instanceof SupplierConfirmation) {
                return SupplierConfirmationResource::getUrl('view', ['record' => $confirmation]);
            }
        }

        return PurchaseOrderResource::getUrl('view', ['record' => $record]);
    }
}
