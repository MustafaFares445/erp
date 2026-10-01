<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchasePermission;
use App\Filament\Resources\PurchaseInbounds\PurchaseInboundResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Services\Purchasing\PurchaseOrderWorkflowService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

final class PurchasingUpcomingReceipts extends TableWidget
{
    protected static ?string $heading = 'Upcoming deliveries';

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
            ->query(PurchaseOrder::query()
                ->with(['supplier', 'purchaseInbound.lines.allocations.warehouse', 'lines.purchaseInboundLine.allocations'])
                ->whereNotNull('sent_at')
                ->whereNotNull('expected_at')
                ->whereIn('status', [
                    PurchaseOrderStatus::Accepted->value,
                    PurchaseOrderStatus::PartiallyReceived->value,
                ])
                ->orderBy('expected_at'))
            ->columns([
                TextColumn::make('purchase_order_number')
                    ->label(__('Purchase Order'))
                    ->description(fn (PurchaseOrder $record): string => $record->supplier->name)
                    ->badge(),
                TextColumn::make('expected_at')
                    ->label(__('Expected'))
                    ->date()
                    ->sinceTooltip()
                    ->color(fn (PurchaseOrder $record): string => $record->expected_at?->isPast() ? 'danger' : 'gray'),
                TextColumn::make('receiving_state')
                    ->label(__('Receiving'))
                    ->state(fn (PurchaseOrder $record): string => app(PurchaseOrderWorkflowService::class)->project($record)->logisticsState)
                    ->badge(),
                TextColumn::make('total_amount')
                    ->label(__('PO value'))
                    ->money(fn (PurchaseOrder $record): string => $record->currency_code),
                TextColumn::make('open')
                    ->label('')
                    ->state(__('Open inbound'))
                    ->color('primary')
                    ->url(fn (PurchaseOrder $record): string => $record->purchaseInbound !== null
                        ? PurchaseInboundResource::getUrl('view', ['record' => $record->purchaseInbound])
                        : PurchaseOrderResource::getUrl('view', ['record' => $record])),
            ])
            ->recordUrl(fn (PurchaseOrder $record): string => $record->purchaseInbound !== null
                ? PurchaseInboundResource::getUrl('view', ['record' => $record->purchaseInbound])
                : PurchaseOrderResource::getUrl('view', ['record' => $record]))
            ->paginated([5, 10]);
    }
}
