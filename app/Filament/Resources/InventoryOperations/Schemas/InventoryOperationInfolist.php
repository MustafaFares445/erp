<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryOperations\Schemas;

use App\Enums\DeliveryType;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Enums\TransferDiscrepancyDisposition;
use App\Filament\AdminModuleRegistry;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\Order;
use App\Models\PurchaseOrder;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

final class InventoryOperationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.inventory.operation.workflow.section'))
                ->columns(2)
                ->schema([
                    TextEntry::make('workflow_stage')
                        ->label(__('admin.inventory.operation.workflow.current_stage'))
                        ->state(fn (InventoryOperation $record): string => $record->stageLabel())
                        ->badge(),
                    TextEntry::make('workflow_source')
                        ->label(__('admin.inventory.operation.workflow.source_document'))
                        ->state(fn (InventoryOperation $record): string => self::sourceDocumentLabel($record))
                        ->url(fn (InventoryOperation $record): ?string => self::sourceDocumentUrl($record))
                        ->placeholder('—'),
                    TextEntry::make('workflow_next_action')
                        ->label(__('admin.inventory.operation.workflow.next_action'))
                        ->state(fn (InventoryOperation $record): string => self::nextAction($record))
                        ->columnSpanFull(),
                    TextEntry::make('workflow_stock_impact')
                        ->label(__('admin.inventory.operation.workflow.stock_impact'))
                        ->state(fn (InventoryOperation $record): string => self::stockImpact($record))
                        ->columnSpanFull(),
                ]),
            Section::make()->columns(2)->schema([
                TextEntry::make('operation_number')->label(__('admin.inventory.operation.fields.operation_number'))->placeholder(__('admin.inventory.adjustment.number_pending')),
                TextEntry::make('stage')->badge()->formatStateUsing(fn (mixed $state, InventoryOperation $record): string => $record->stageLabel()),
                TextEntry::make('sourceWarehouse.name')->label(__('admin.inventory.operation.fields.source_warehouse')),
                TextEntry::make('destinationWarehouse.name')->label(__('admin.inventory.operation.fields.destination_warehouse')),
                TextEntry::make('supplier.name')->label(__('admin.inventory.operation.fields.supplier')),
                TextEntry::make('customer.company_name')
                    ->label(__('admin.inventory.operation.fields.customer'))
                    ->visible(fn (InventoryOperation $record): bool => $record->operation_type === OperationType::Delivery),
                TextEntry::make('delivery_type')
                    ->label(__('admin.inventory.operation.fields.delivery_type'))
                    ->formatStateUsing(fn (?DeliveryType $state): ?string => $state?->label())
                    ->badge()
                    ->visible(fn (InventoryOperation $record): bool => $record->operation_type === OperationType::Delivery),
                TextEntry::make('scheduled_at')->label(__('admin.inventory.operation.fields.scheduled_at'))->dateTime(),
                TextEntry::make('notes')->label(__('admin.inventory.operation.fields.notes'))->columnSpanFull(),
            ]),
            Section::make(__('admin.sections.operations'))->gridContainer()->schema([
                RepeatableEntry::make('lines')->label('')->columns([
                    'default' => 1,
                    '@sm' => 2,
                    '@lg' => 4,
                ])->schema([
                    TextEntry::make('productVariant.sku')->label(__('admin.inventory.operation.fields.product')),
                    TextEntry::make('quantity')->label(__('admin.inventory.operation.fields.demand')),
                    TextEntry::make('unit.name')->label(__('admin.inventory.operation.fields.unit')),
                    TextEntry::make('is_picked')
                        ->label(__('admin.inventory.operation.fields.warehouse_preparation'))
                        ->hintIcon(Heroicon::QuestionMarkCircle, __('admin.inventory.operation.help.picked'))
                        ->formatStateUsing(fn (bool $state): string => $state
                            ? __('admin.inventory.operation.values.prepared')
                            : __('admin.inventory.operation.values.not_prepared'))
                        ->badge()
                        ->color(fn (InventoryOperationLine $record): string => $record->is_picked ? 'success' : 'gray'),
                    TextEntry::make('dispatched_base_quantity')
                        ->label(__('admin.inventory.operation.fields.dispatched_quantity'))
                        ->visible(fn (InventoryOperationLine $record): bool => $record->operation?->operation_type === OperationType::InternalTransfer),
                    TextEntry::make('received_base_quantity')
                        ->label(__('admin.inventory.operation.fields.received_quantity'))
                        ->visible(fn (InventoryOperationLine $record): bool => $record->operation?->operation_type === OperationType::InternalTransfer),
                    TextEntry::make('discrepancy_disposition')
                        ->label(__('admin.inventory.operation.fields.discrepancy_disposition'))
                        ->formatStateUsing(fn (?TransferDiscrepancyDisposition $state): ?string => $state?->name)
                        ->visible(fn (InventoryOperationLine $record): bool => $record->operation?->operation_type === OperationType::InternalTransfer),
                ]),
            ]),
            Section::make(__('admin.inventory.operation.sections.related_documents'))
                ->visible(fn (InventoryOperation $record): bool => $record->operation_type === OperationType::Delivery)
                ->schema(DeliveryRelatedDocuments::make())
                ->gridContainer()
                ->columns([
                    'default' => 1,
                    '@lg' => 2,
                ]),
        ]);
    }

    private static function nextAction(InventoryOperation $operation): string
    {
        return match ($operation->stage) {
            OperationStage::Draft => __('admin.inventory.operation.workflow.draft_next'),
            OperationStage::Waiting => __('admin.inventory.operation.workflow.waiting_next'),
            OperationStage::Ready => match ($operation->operation_type) {
                OperationType::Receipt => __('admin.inventory.operation.workflow.receipt_ready_next'),
                OperationType::Delivery => __('admin.inventory.operation.workflow.delivery_ready_next'),
                OperationType::InternalTransfer => __('admin.inventory.operation.workflow.transfer_ready_next'),
            },
            OperationStage::InTransit, OperationStage::PartiallyReceived => __('admin.inventory.operation.workflow.transfer_receive_next'),
            OperationStage::Done => $operation->operation_type === OperationType::Delivery
                ? __('admin.inventory.operation.workflow.delivery_done_next')
                : __('admin.inventory.operation.workflow.done_next'),
            OperationStage::Canceled => __('admin.inventory.operation.workflow.canceled_next'),
        };
    }

    private static function stockImpact(InventoryOperation $operation): string
    {
        return match ($operation->stage) {
            OperationStage::Draft => __('admin.inventory.operation.workflow.draft_impact'),
            OperationStage::Waiting => __('admin.inventory.operation.workflow.waiting_impact'),
            OperationStage::Ready => match ($operation->operation_type) {
                OperationType::Receipt => __('admin.inventory.operation.workflow.receipt_ready_impact'),
                OperationType::Delivery => __('admin.inventory.operation.workflow.delivery_ready_impact'),
                OperationType::InternalTransfer => __('admin.inventory.operation.workflow.transfer_ready_impact'),
            },
            OperationStage::InTransit, OperationStage::PartiallyReceived => __('admin.inventory.operation.workflow.transfer_transit_impact'),
            OperationStage::Done => match ($operation->operation_type) {
                OperationType::Receipt => __('admin.inventory.operation.workflow.receipt_done_impact'),
                OperationType::Delivery => __('admin.inventory.operation.workflow.delivery_done_impact'),
                OperationType::InternalTransfer => __('admin.inventory.operation.workflow.transfer_done_impact'),
            },
            OperationStage::Canceled => __('admin.inventory.operation.workflow.canceled_impact'),
        };
    }

    private static function sourceDocumentLabel(InventoryOperation $operation): string
    {
        if (! is_int($operation->source_document_id)) {
            return '—';
        }

        return match ($operation->source_document_type) {
            PurchaseOrder::class => self::purchaseOrderReference($operation->source_document_id),
            Order::class => self::orderReference($operation->source_document_id),
            default => class_basename((string) $operation->source_document_type).' #'.$operation->source_document_id,
        };
    }

    private static function purchaseOrderReference(int $purchaseOrderId): string
    {
        $purchaseOrder = PurchaseOrder::query()->whereKey($purchaseOrderId)->first();

        return $purchaseOrder instanceof PurchaseOrder
            && $purchaseOrder->purchase_order_number !== ''
                ? $purchaseOrder->purchase_order_number
                : __('admin.resources.purchase_orders').' #'.$purchaseOrderId;
    }

    private static function orderReference(int $orderId): string
    {
        $order = Order::query()->whereKey($orderId)->first();

        return $order instanceof Order
            && $order->order_number !== ''
                ? $order->order_number
                : __('admin.resources.orders').' #'.$orderId;
    }

    private static function sourceDocumentUrl(InventoryOperation $operation): ?string
    {
        if (! is_int($operation->source_document_id)) {
            return null;
        }

        $resource = match ($operation->source_document_type) {
            PurchaseOrder::class => PurchaseOrderResource::class,
            Order::class => OrderResource::class,
            default => null,
        };

        return is_string($resource)
            ? AdminModuleRegistry::resolveResourceRecordLink($resource, $operation->source_document_id)
            : null;
    }
}
