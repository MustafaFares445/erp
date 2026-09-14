<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseInbounds\Schemas;

use App\Data\Inventory\LogisticsInboundBlockerData;
use App\Models\PurchaseInbound;
use App\Services\Inventory\LogisticsInboundProjectionService;
use Filament\Infolists\Components\RepeatableEntry;
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
                TextEntry::make('purchaseOrder.expected_at')->label(__('admin.logistics.inbound.expected_date'))->date()->placeholder('—'),
                TextEntry::make('business_state')
                    ->label(__('admin.logistics.inbound.business_state'))
                    ->state(fn (PurchaseInbound $record): string => app(LogisticsInboundProjectionService::class)->project($record)->businessState)
                    ->badge(),
                TextEntry::make('confirmed_quantity')
                    ->label(__('admin.logistics.inbound.confirmed'))
                    ->state(fn (PurchaseInbound $record): string => app(LogisticsInboundProjectionService::class)->project($record)->confirmedBaseQuantity),
                TextEntry::make('allocated_quantity')
                    ->label(__('admin.logistics.inbound.allocated'))
                    ->state(fn (PurchaseInbound $record): string => app(LogisticsInboundProjectionService::class)->project($record)->allocatedBaseQuantity),
                TextEntry::make('received_quantity')
                    ->label(__('admin.logistics.inbound.received'))
                    ->state(fn (PurchaseInbound $record): string => app(LogisticsInboundProjectionService::class)->project($record)->receivedBaseQuantity),
                TextEntry::make('remaining_quantity')
                    ->label(__('admin.logistics.inbound.remaining'))
                    ->state(fn (PurchaseInbound $record): string => app(LogisticsInboundProjectionService::class)->project($record)->remainingBaseQuantity),
                TextEntry::make('next_action')
                    ->label(__('admin.logistics.fields.next_action'))
                    ->state(fn (PurchaseInbound $record): string => app(LogisticsInboundProjectionService::class)->project($record)->nextAction),
            ]),
            Section::make(__('admin.logistics.fields.blockers'))
                ->visible(fn (PurchaseInbound $record): bool => app(LogisticsInboundProjectionService::class)->project($record)->blockers !== [])
                ->schema([
                    RepeatableEntry::make('logistics_blockers')
                        ->label('')
                        ->state(fn (PurchaseInbound $record): array => array_map(
                            static fn (LogisticsInboundBlockerData $blocker): array => [
                                'message' => $blocker->message,
                                'severity' => ucfirst($blocker->severity),
                            ],
                            app(LogisticsInboundProjectionService::class)->project($record)->blockers,
                        ))
                        ->columns(2)
                        ->schema([
                            TextEntry::make('message')->label(__('admin.logistics.fields.blockers')),
                            TextEntry::make('severity')->label(__('admin.logistics.inbound.severity'))->badge(),
                        ]),
                ]),
        ]);
    }
}
