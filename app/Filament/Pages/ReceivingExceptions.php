<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Data\Inventory\LogisticsInboundBlockerData;
use App\Data\Inventory\LogisticsInboundData;
use App\Enums\InventoryPermission;
use App\Enums\OperationStage;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\PurchaseInbounds\PurchaseInboundResource;
use App\Models\InventoryOperation;
use App\Models\PurchaseInbound;
use App\Services\Inventory\LogisticsInboundProjectionService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Relations\Relation;

final class ReceivingExceptions extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.receiving_exceptions');
    }

    #[\Override]
    public function getTitle(): string
    {
        return __('admin.resources.receiving_exceptions');
    }

    #[\Override]
    public static function canAccess(): bool
    {
        return auth()->user()?->can(InventoryPermission::ReceiptView->value) ?? false;
    }

    #[\Override]
    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(PurchaseInbound::query()
                ->whereNotIn('status', ['cancelled', 'received'])
                ->with([
                    'purchaseOrder.supplier',
                    'purchaseOrder.receipts' => static fn (Relation $query): Relation => $query
                        ->whereIn('stage', [OperationStage::Draft->value, OperationStage::Waiting->value]),
                    'lines.purchaseOrderLine.productVariant.product',
                    'lines.purchaseOrderLine.productVariant.unit',
                    'lines.allocations.warehouse',
                ]))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label(__('admin.resources.expected_inbound'))
                    ->prefix('INB-')
                    ->sortable(),
                TextColumn::make('purchaseOrder.purchase_order_number')
                    ->label(__('admin.purchasing.fields.purchase_order'))
                    ->searchable(),
                TextColumn::make('purchaseOrder.supplier.name')
                    ->label(__('admin.operation.fields.supplier'))
                    ->searchable(),
                TextColumn::make('exception_state')
                    ->label(__('admin.logistics.fields.blockers'))
                    ->getStateUsing(fn (PurchaseInbound $record): string => self::projection($record)->businessState)
                    ->badge()
                    ->color(fn (PurchaseInbound $record): string => self::stateColor(self::projection($record)->businessState)),
                TextColumn::make('exceptions')
                    ->label(__('admin.resources.receiving_exceptions'))
                    ->getStateUsing(fn (PurchaseInbound $record): array => self::exceptions($record))
                    ->listWithLineBreaks(),
                TextColumn::make('next_action')
                    ->label(__('admin.logistics.fields.next_action'))
                    ->getStateUsing(fn (PurchaseInbound $record): string => self::projection($record)->nextAction),
            ])
            ->recordActions([
                Action::make('viewExpectedInbound')
                    ->label(__('admin.actions.view'))
                    ->url(fn (PurchaseInbound $record): string => PurchaseInboundResource::getUrl('view', ['record' => $record])),
                Action::make('completeReceipt')
                    ->label(__('admin.logistics.actions.complete_receipt'))
                    ->visible(fn (PurchaseInbound $record): bool => self::openReceipt($record) instanceof InventoryOperation)
                    ->url(function (PurchaseInbound $record): ?string {
                        $receipt = self::openReceipt($record);

                        return $receipt instanceof InventoryOperation
                            ? InventoryOperationResource::getUrl('edit', ['record' => $receipt])
                            : null;
                    }),
            ]);
    }

    private static function projection(PurchaseInbound $record): LogisticsInboundData
    {
        /** @var array<int, LogisticsInboundData> $cache */
        static $cache = [];

        return $cache[$record->id] ??= app(LogisticsInboundProjectionService::class)->project($record);
    }

    /** @return list<string> */
    private static function exceptions(PurchaseInbound $record): array
    {
        $projection = self::projection($record);
        $exceptions = array_map(static fn (LogisticsInboundBlockerData $blocker): string => $blocker->message, $projection->blockers);

        if ($projection->overdue) {
            $exceptions[] = __('admin.logistics.exceptions.overdue_expected_inbound');
        }

        if (self::openReceipt($record) instanceof InventoryOperation) {
            $exceptions[] = __('admin.logistics.exceptions.open_draft_receipt');
        }

        if ($projection->businessState === 'Partially Received') {
            $exceptions[] = __('admin.logistics.exceptions.partial_receipt_remaining');
        }

        return $exceptions === []
            ? [__('admin.logistics.exceptions.review_inbound')]
            : array_values(array_unique($exceptions));
    }

    private static function openReceipt(PurchaseInbound $inbound): ?InventoryOperation
    {
        $receipt = $inbound->purchaseOrder->receipts->first();

        return $receipt instanceof InventoryOperation ? $receipt : null;
    }

    private static function stateColor(string $state): string
    {
        return match ($state) {
            'Needs Attention' => 'danger',
            'Awaiting Supplier Confirmation', 'Awaiting Allocation', 'Waiting for Supplier Backorder' => 'warning',
            'Ready to Receive', 'Partially Received' => 'info',
            default => 'gray',
        };
    }
}
