<?php

declare(strict_types=1);

namespace App\Filament\Resources\OutboundFulfillments;

use App\Enums\InventoryPermission;
use App\Enums\OrderStatus;
use App\Filament\Resources\OutboundFulfillments\Pages\ListOutboundFulfillments;
use App\Filament\Resources\OutboundFulfillments\Pages\ViewOutboundFulfillment;
use App\Models\Order;
use App\Models\OrderLine;
use App\Services\Sales\OrderWorkflowService;
use App\Support\QuantityFormatter;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class OutboundFulfillmentResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.inventory';

    protected static ?int $navigationSort = 35;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return 'Outbound Fulfillment';
    }

    #[\Override]
    public static function canViewAny(): bool
    {
        return auth()->user()?->can(InventoryPermission::DeliveryView->value) ?? false;
    }

    #[\Override]
    public static function canView(Model $record): bool
    {
        return $record instanceof Order && self::canViewAny();
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[\Override]
    public static function canEdit(Model $record): bool
    {
        return false;
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('scheduled_at')
            ->columns([
                TextColumn::make('order_number')->label('Customer Order')->searchable()->sortable(),
                TextColumn::make('customer.company_name')->label('Customer')->searchable(),
                TextColumn::make('scheduled_at')->label('Requested')->date()->sortable(),
                TextColumn::make('logistics_milestone')
                    ->label('Logistics milestone')
                    ->state(fn (Order $record): string => app(OrderWorkflowService::class)->project($record)->businessMilestone)
                    ->badge(),
                TextColumn::make('remaining')
                    ->label('Remaining')
                    ->state(fn (Order $record): string => QuantityFormatter::display(app(OrderWorkflowService::class)->project($record)->remainingBase)),
                TextColumn::make('blocker')
                    ->label('Blocker')
                    ->state(fn (Order $record): ?string => app(OrderWorkflowService::class)->project($record)->blockerMessage)
                    ->placeholder('—')
                    ->limit(45),
            ])
            ->filters([
                SelectFilter::make('queue')
                    ->label('Queue')
                    ->options([
                        'awaiting_allocation' => 'Awaiting Allocation',
                        'supply_blocked' => 'Supply Blocked',
                        'ready' => 'Ready to Dispatch',
                        'in_transit' => 'In Transit',
                        'delivered' => 'Delivered',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'awaiting_allocation' => $query->where('status', OrderStatus::Released->value)->whereDoesntHave('deliveries', fn (Builder $delivery): Builder => $delivery->where('stage', '!=', 'canceled')),
                        'supply_blocked' => $query->whereHas('procurementRequirements', fn (Builder $requirement): Builder => $requirement->whereNotIn('status', ['fulfilled', 'cancelled'])),
                        'ready' => $query->whereHas('deliveries', fn (Builder $delivery): Builder => $delivery->where('stage', 'ready')),
                        'in_transit' => $query->whereHas('shipments', fn (Builder $shipment): Builder => $shipment->where('status', 'in_transit')),
                        'delivered' => $query->whereHas('shipments', fn (Builder $shipment): Builder => $shipment->where('status', 'arrived')),
                        default => $query,
                    }),
            ])
            ->recordActions([ViewAction::make()]);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Outbound demand')->columns(4)->schema([
                TextEntry::make('order_number')->label('Customer Order'),
                TextEntry::make('customer.company_name')->label('Customer'),
                TextEntry::make('scheduled_at')->label('Requested date')->date()->placeholder('—'),
                TextEntry::make('milestone')->label('Milestone')->state(fn (Order $record): string => app(OrderWorkflowService::class)->project($record)->businessMilestone)->badge(),
                TextEntry::make('requested_qty')->label('Requested')->state(fn (Order $record): string => QuantityFormatter::display(app(OrderWorkflowService::class)->project($record)->requestedBase)),
                TextEntry::make('planned_qty')->label('Planned')->state(fn (Order $record): string => QuantityFormatter::display(app(OrderWorkflowService::class)->project($record)->plannedBase)),
                TextEntry::make('ready_qty')->label('Ready')->state(fn (Order $record): string => QuantityFormatter::display(app(OrderWorkflowService::class)->project($record)->readyBase)),
                TextEntry::make('remaining_qty')->label('Remaining')->state(fn (Order $record): string => QuantityFormatter::display(app(OrderWorkflowService::class)->project($record)->remainingBase)),
                TextEntry::make('blocker')->label('Blocker')->state(fn (Order $record): ?string => app(OrderWorkflowService::class)->project($record)->blockerMessage)->placeholder('No blocker')->columnSpanFull(),
            ]),
            Section::make('Demand by line')->schema([
                RepeatableEntry::make('lines')->columns(4)->schema([
                    TextEntry::make('productVariant.sku')->label('Product'),
                    TextEntry::make('base_quantity')
                        ->label('Requested base qty')
                        ->state(fn (OrderLine $record): string => QuantityFormatter::display($record->base_quantity)),
                    TextEntry::make('short_closed_base_quantity')
                        ->label('Short-closed')
                        ->state(fn (OrderLine $record): string => QuantityFormatter::display($record->short_closed_base_quantity)),
                    TextEntry::make('unit.name')->label('Commercial UOM')->placeholder('—'),
                ]),
            ]),
            Section::make('Planned deliveries')->schema([
                RepeatableEntry::make('deliveries')->columns(5)->schema([
                    TextEntry::make('operation_number')->label('Delivery')->placeholder('Draft'),
                    TextEntry::make('sourceWarehouse.name')->label('Warehouse'),
                    TextEntry::make('stage')->badge(),
                    TextEntry::make('shipment.tracking_number')->label('Tracking')->placeholder('—'),
                    TextEntry::make('shipment.status')->label('Shipment')->badge()->placeholder('—'),
                ]),
            ]),
            Section::make('Supply blocker')->schema([
                RepeatableEntry::make('procurementRequirements')->columns(4)->schema([
                    TextEntry::make('productVariant.sku')->label('Product'),
                    TextEntry::make('required_base_quantity')->label('Required')->formatStateUsing(QuantityFormatter::display(...)),
                    TextEntry::make('fulfilled_base_quantity')->label('Received')->formatStateUsing(QuantityFormatter::display(...)),
                    TextEntry::make('status')->badge(),
                ]),
            ]),
        ]);
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('status', OrderStatus::Released->value)
            ->with([
                'customer', 'lines.productVariant', 'lines.unit',
                'deliveries.sourceWarehouse', 'deliveries.shipment',
                'procurementRequirements.productVariant', 'shipments', 'invoices',
            ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListOutboundFulfillments::route('/'),
            'view' => ViewOutboundFulfillment::route('/{record}'),
        ];
    }
}
