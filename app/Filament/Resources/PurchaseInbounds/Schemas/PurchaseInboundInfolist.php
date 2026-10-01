<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseInbounds\Schemas;

use App\Data\Inventory\LogisticsInboundBlockerData;
use App\Models\PurchaseInbound;
use App\Services\Inventory\LogisticsInboundProjectionService;
use App\Support\QuantityFormatter;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class PurchaseInboundInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.logistics.inbound.summary'))->columns(4)->schema([
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
                TextEntry::make('blockers')
                    ->label(__('admin.logistics.inbound.blockers'))
                    ->state(fn (PurchaseInbound $record): array => array_map(
                        static fn (LogisticsInboundBlockerData $blocker): string => __('admin.logistics.inbound.blocker_messages.'.$blocker->code),
                        app(LogisticsInboundProjectionService::class)->project($record)->blockers,
                    ))
                    ->listWithLineBreaks()
                    ->placeholder(__('admin.logistics.inbound.no_active_blockers'))
                    ->columnSpan(3),
                TextEntry::make('next_action')
                    ->label(__('admin.logistics.fields.next_action'))
                    ->state(fn (PurchaseInbound $record): string => __(sprintf(
                        'admin.logistics.inbound.next_actions.%s',
                        str(app(LogisticsInboundProjectionService::class)->project($record)->nextAction)->snake()->toString(),
                    ))),
            ]),
        ]);
    }
}
