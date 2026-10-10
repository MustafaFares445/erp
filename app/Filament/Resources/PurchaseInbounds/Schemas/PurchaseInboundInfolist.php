<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseInbounds\Schemas;

use App\Data\Inventory\LogisticsInboundBlockerData;
use App\Data\Inventory\LogisticsInboundLineData;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Filament\Components\InventoryViewDiagnostics;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseInboundLine;
use App\Services\Inventory\LogisticsInboundProjectionService;
use App\Support\QuantityFormatter;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Purchase inbound View page: Operational health (PurchaseInboundDiagnoser), key figures,
 * the inbound summary and its blockers. Lines and allocations follow in the relation manager.
 */
final class PurchaseInboundInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            InventoryViewDiagnostics::section(),
            InventoryViewDiagnostics::metrics(static fn (PurchaseInbound $record): array => self::metrics($record)),
            Section::make(__('admin.logistics.inbound.summary'))->columnSpanFull()->columns(['default' => 1, 'md' => 2, 'xl' => 4])->schema([
                TextEntry::make('inbound_number')
                    ->label(__('admin.logistics.inbound.number'))
                    ->state(fn (PurchaseInbound $record): string => 'INB-'.$record->id),
                TextEntry::make('purchaseOrder.purchase_order_number')->label(__('admin.logistics.inbound.purchase_order_reference')),
                TextEntry::make('purchaseOrder.supplier.name')->label(__('admin.logistics.inbound.supplier')),
                TextEntry::make('purchaseOrder.expected_at')->label(__('admin.logistics.inbound.expected_date'))->date()->placeholder(__('—')),
                TextEntry::make('business_state')
                    ->label(__('admin.logistics.inbound.business_state'))
                    ->state(fn (PurchaseInbound $record): string => __(sprintf(
                        'admin.logistics.inbound.states.%s',
                        str(app(LogisticsInboundProjectionService::class)->project($record)->businessState)->replace(' / ', ' ')->snake()->toString(),
                    )))
                    ->badge(),
                TextEntry::make('next_action')
                    ->label(__('admin.logistics.fields.next_action'))
                    ->state(fn (PurchaseInbound $record): string => __(sprintf(
                        'admin.logistics.inbound.next_actions.%s',
                        str(app(LogisticsInboundProjectionService::class)->project($record)->nextAction)->snake()->toString(),
                    ))),
            ]),
            Section::make(__('inventory_view_ux.ui.inbound_quantities'))->columnSpanFull()->columns(['default' => 1, 'sm' => 2, 'xl' => 4])->schema([
                TextEntry::make('confirmed_quantity')
                    ->label(__('admin.logistics.inbound.confirmed'))
                    ->state(fn (PurchaseInbound $record): string => QuantityFormatter::display(app(LogisticsInboundProjectionService::class)->project($record)->confirmedBaseQuantity)),
                TextEntry::make('allocated_quantity')
                    ->label(__('admin.logistics.inbound.allocated'))
                    ->state(fn (PurchaseInbound $record): string => QuantityFormatter::display(app(LogisticsInboundProjectionService::class)->project($record)->allocatedBaseQuantity)),
                TextEntry::make('received_quantity')
                    ->label(__('admin.logistics.inbound.received'))
                    ->state(fn (PurchaseInbound $record): string => QuantityFormatter::display(app(LogisticsInboundProjectionService::class)->project($record)->receivedBaseQuantity)),
                TextEntry::make('remaining_quantity')
                    ->label(__('admin.logistics.inbound.remaining'))
                    ->state(fn (PurchaseInbound $record): string => QuantityFormatter::display(app(LogisticsInboundProjectionService::class)->project($record)->remainingBaseQuantity)),
            ]),
            Section::make(__('admin.logistics.inbound.blockers'))->columnSpanFull()->schema([
                TextEntry::make('blockers')
                    ->label(__('admin.logistics.inbound.blockers'))
                    ->hiddenLabel()
                    ->state(fn (PurchaseInbound $record): array => array_map(
                        static fn (LogisticsInboundBlockerData $blocker): string => __('admin.logistics.inbound.blocker_messages.'.$blocker->code),
                        app(LogisticsInboundProjectionService::class)->project($record)->blockers,
                    ))
                    ->listWithLineBreaks()
                    ->placeholder(__('admin.logistics.inbound.no_active_blockers')),
            ]),
        ]);
    }

    /**
     * @return list<array{label: string, value: string, helper?: string|null, tone?: string|null}>
     */
    private static function metrics(PurchaseInbound $record): array
    {
        $projection = app(LogisticsInboundProjectionService::class)->project($record);
        $confirmed = (float) $projection->confirmedBaseQuantity;
        $received = (float) $projection->receivedBaseQuantity;
        $progress = $confirmed > 0 ? min(100.0, $received / $confirmed * 100) : 0.0;
        $toAllocate = count(array_filter(
            $projection->lines,
            static fn (LogisticsInboundLineData $line): bool => (float) $line->currentlyAllocatableBaseQuantity > 0.000001,
        ));
        $openReceipts = self::openReceiptCount($record);

        return [
            [
                'label' => __('inventory_view_ux.ui.inbound_progress'),
                'value' => QuantityFormatter::display(round($progress, 1)).'%',
                'helper' => __('inventory_view_ux.ui.inbound_progress_helper', [
                    'received' => QuantityFormatter::display($projection->receivedBaseQuantity),
                    'confirmed' => QuantityFormatter::display($projection->confirmedBaseQuantity),
                ]),
                'tone' => 'primary',
            ],
            [
                'label' => __('admin.logistics.inbound.remaining'),
                'value' => QuantityFormatter::display($projection->remainingBaseQuantity),
                'tone' => (float) $projection->remainingBaseQuantity > 0 && $projection->overdue ? 'warning' : null,
            ],
            [
                'label' => __('inventory_view_ux.ui.inbound_lines_to_allocate'),
                'value' => (string) $toAllocate,
                'tone' => $toAllocate > 0 ? 'warning' : null,
            ],
            [
                'label' => __('inventory_view_ux.ui.inbound_open_receipts'),
                'value' => (string) $openReceipts,
            ],
            [
                'label' => __('admin.logistics.inbound.blockers'),
                'value' => (string) count($projection->blockers),
                'tone' => $projection->blockers !== [] ? 'danger' : null,
            ],
        ];
    }

    /** Counts open receipts from the relations the projection already loaded. */
    private static function openReceiptCount(PurchaseInbound $record): int
    {
        $receipts = [];

        foreach ($record->lines as $line) {
            assert($line instanceof PurchaseInboundLine);

            foreach ($line->allocations as $allocation) {
                assert($allocation instanceof PurchaseInboundAllocation);

                foreach ($allocation->inventoryOperationLines as $operationLine) {
                    assert($operationLine instanceof InventoryOperationLine);
                    $operation = $operationLine->operation;

                    if ($operation instanceof InventoryOperation
                        && $operation->operation_type === OperationType::Receipt
                        && in_array($operation->stage, [OperationStage::Draft, OperationStage::Waiting, OperationStage::Ready], true)) {
                        $receipts[$operation->getKey()] = true;
                    }
                }
            }
        }

        return count($receipts);
    }
}
