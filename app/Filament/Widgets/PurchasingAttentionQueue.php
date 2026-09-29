<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\BillStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchasePermission;
use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\PurchaseInbounds\PurchaseInboundResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\SupplierConfirmations\SupplierConfirmationResource;
use App\Models\Bill;
use App\Models\PurchaseOrder;
use App\Models\SupplierConfirmation;
use App\Services\Purchasing\PurchaseOrderWorkflowService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

final class PurchasingAttentionQueue extends TableWidget
{
    protected static ?string $heading = 'Needs your attention';

    protected int|string|array $columnSpan = 'full';

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(PurchasePermission::OrderView->value) ?? false;
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->query(self::query())
            ->defaultSort('expected_at', 'asc')
            ->columns([
                TextColumn::make('purchase_order_number')
                    ->label('Purchase Order')
                    ->description(fn (PurchaseOrder $record): string => $record->supplier->name)
                    ->badge(),
                TextColumn::make('attention')
                    ->label('Why it needs attention')
                    ->state(fn (PurchaseOrder $record): string => self::attentionReason($record))
                    ->badge()
                    ->color(fn (PurchaseOrder $record): string => self::attentionColor($record)),
                TextColumn::make('expected_at')
                    ->label('Expected')
                    ->date()
                    ->placeholder('Not specified')
                    ->color(fn (PurchaseOrder $record): string => self::isOverdue($record) ? 'danger' : 'gray'),
                TextColumn::make('owner')
                    ->label('Owner')
                    ->state(fn (PurchaseOrder $record): string => app(PurchaseOrderWorkflowService::class)->project($record)->nextOwner),
                TextColumn::make('action')
                    ->label('Action')
                    ->state(fn (PurchaseOrder $record): string => app(PurchaseOrderWorkflowService::class)->project($record)->nextAction)
                    ->color('primary')
                    ->url(fn (PurchaseOrder $record): string => self::actionUrl($record)),
            ])
            ->recordUrl(fn (PurchaseOrder $record): string => self::actionUrl($record))
            ->paginated([5, 10]);
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

    private static function attentionReason(PurchaseOrder $record): string
    {
        if (self::isOverdue($record)) {
            return 'Overdue delivery';
        }

        $projection = app(PurchaseOrderWorkflowService::class)->project($record);

        return $projection->blocker ?? $projection->businessState;
    }

    private static function attentionColor(PurchaseOrder $record): string
    {
        if (self::isOverdue($record)) {
            return 'danger';
        }

        return app(PurchaseOrderWorkflowService::class)->project($record)->blocker === null
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
        $projection = app(PurchaseOrderWorkflowService::class)->project($record);

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
