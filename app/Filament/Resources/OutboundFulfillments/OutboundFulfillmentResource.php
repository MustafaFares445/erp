<?php

declare(strict_types=1);

namespace App\Filament\Resources\OutboundFulfillments;

use App\Enums\InventoryPermission;
use App\Enums\OrderStatus;
use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\OutboundFulfillments\Pages\ListOutboundFulfillments;
use App\Filament\Resources\OutboundFulfillments\Pages\ViewOutboundFulfillment;
use App\Filament\Resources\Shipments\ShipmentResource;
use App\Models\InventoryOperation;
use App\Models\Order;
use App\Models\OrderLine;
use App\Services\Sales\OrderWorkflowService;
use App\Support\QuantityFormatter;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
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
        return __('admin.inventory.outbound.title');
    }

    #[\Override]
    public static function getModelLabel(): string
    {
        return __('admin.inventory.outbound.title');
    }

    #[\Override]
    public static function getPluralModelLabel(): string
    {
        return __('admin.inventory.outbound.title');
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
                TextColumn::make('order_number')->label(__('admin.inventory.outbound.fields.order'))->searchable()->sortable(),
                TextColumn::make('customer.company_name')->label(__('admin.inventory.outbound.fields.customer'))->searchable(),
                TextColumn::make('scheduled_at')->label(__('admin.inventory.outbound.fields.requested_date'))->date()->sortable(),
                TextColumn::make('logistics_milestone')
                    ->label(__('admin.inventory.outbound.fields.milestone'))
                    ->state(fn (Order $record): string => app(OrderWorkflowService::class)->project($record)->businessMilestone)
                    ->badge(),
                TextColumn::make('remaining')
                    ->label(__('admin.inventory.outbound.fields.remaining'))
                    ->state(fn (Order $record): string => QuantityFormatter::display(app(OrderWorkflowService::class)->project($record)->remainingBase)),
                TextColumn::make('blocker')
                    ->label(__('admin.inventory.outbound.fields.blocker'))
                    ->state(fn (Order $record): ?string => app(OrderWorkflowService::class)->project($record)->blockerMessage)
                    ->placeholder(__('—'))
                    ->limit(45),
            ])
            ->filters([
                SelectFilter::make('queue')
                    ->label(__('admin.inventory.outbound.fields.queue'))
                    ->options([
                        'awaiting_allocation' => __('admin.inventory.outbound.queues.awaiting_allocation'),
                        'supply_blocked' => __('admin.inventory.outbound.queues.supply_blocked'),
                        'ready' => __('admin.inventory.outbound.queues.ready'),
                        'in_transit' => __('admin.inventory.outbound.queues.in_transit'),
                        'delivered' => __('admin.inventory.outbound.queues.delivered'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'awaiting_allocation' => $query->where('status', OrderStatus::Released->value)->whereDoesntHave('deliveries', fn (Builder $delivery): Builder => $delivery->where('stage', '!=', 'canceled')),
                        'supply_blocked' => $query->whereHas('procurementRequirements', fn (Builder $requirement): Builder => $requirement->whereNotIn('status', ['fulfilled', 'cancelled', 'superseded'])),
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
            Section::make(__('admin.inventory.outbound.sections.demand'))->columns(4)->schema([
                TextEntry::make('order_number')
                    ->label(__('admin.inventory.outbound.fields.order'))
                    ->url(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record])),
                TextEntry::make('customer.company_name')->label(__('admin.inventory.outbound.fields.customer')),
                TextEntry::make('scheduled_at')->label(__('admin.inventory.outbound.fields.requested_date'))->date()->placeholder(__('—')),
                TextEntry::make('milestone')->label(__('admin.inventory.outbound.fields.milestone'))->state(fn (Order $record): string => app(OrderWorkflowService::class)->project($record)->businessMilestone)->badge(),
                TextEntry::make('requested_qty')->label(__('admin.inventory.outbound.fields.requested'))->state(fn (Order $record): string => QuantityFormatter::display(app(OrderWorkflowService::class)->project($record)->requestedBase)),
                TextEntry::make('planned_qty')->label(__('admin.inventory.outbound.fields.planned'))->state(fn (Order $record): string => QuantityFormatter::display(app(OrderWorkflowService::class)->project($record)->plannedBase)),
                TextEntry::make('ready_qty')->label(__('admin.inventory.outbound.fields.ready'))->state(fn (Order $record): string => QuantityFormatter::display(app(OrderWorkflowService::class)->project($record)->readyBase)),
                TextEntry::make('remaining_qty')->label(__('admin.inventory.outbound.fields.remaining'))->state(fn (Order $record): string => QuantityFormatter::display(app(OrderWorkflowService::class)->project($record)->remainingBase)),
                TextEntry::make('blocker')->label(__('admin.inventory.outbound.fields.blocker'))->state(fn (Order $record): ?string => app(OrderWorkflowService::class)->project($record)->blockerMessage)->placeholder(__('admin.inventory.outbound.placeholders.no_blocker'))->columnSpanFull(),
            ]),
            Section::make(__('admin.inventory.outbound.sections.demand_lines'))->schema([
                RepeatableEntry::make('lines')->columns(4)->schema([
                    TextEntry::make('productVariant.sku')->label(__('admin.inventory.outbound.fields.product')),
                    TextEntry::make('base_quantity')
                        ->label(__('admin.inventory.outbound.fields.requested'))
                        ->state(fn (OrderLine $record): string => QuantityFormatter::display($record->base_quantity)),
                    TextEntry::make('short_closed_base_quantity')
                        ->label(__('admin.inventory.outbound.fields.short_closed'))
                        ->state(fn (OrderLine $record): string => QuantityFormatter::display($record->short_closed_base_quantity)),
                    TextEntry::make('unit.name')->label(__('admin.inventory.outbound.fields.commercial_uom'))->placeholder(__('—')),
                ]),
            ]),
            Section::make(__('admin.inventory.outbound.sections.planned_deliveries'))->schema([
                RepeatableEntry::make('deliveries')->columns(5)->schema([
                    TextEntry::make('operation_number')
                        ->label(__('admin.inventory.outbound.fields.delivery'))
                        ->placeholder(__('admin.inventory.outbound.placeholders.draft'))
                        ->url(fn (InventoryOperation $record): string => InventoryOperationResource::getUrl('view', ['record' => $record])),
                    TextEntry::make('sourceWarehouse.name')->label(__('admin.inventory.outbound.fields.warehouse')),
                    TextEntry::make('stage')->badge()->formatStateUsing(fn (mixed $state, InventoryOperation $record): string => $record->stageLabel()),
                    TextEntry::make('shipment.tracking_number')
                        ->label(__('admin.inventory.outbound.fields.tracking'))
                        ->placeholder(__('—'))
                        ->url(fn (InventoryOperation $record): ?string => $record->shipment
                            ? ShipmentResource::getUrl('view', ['record' => $record->shipment])
                            : null),
                    TextEntry::make('shipment.status')->label(__('admin.inventory.outbound.fields.shipment'))->badge()->placeholder(__('—')),
                ]),
            ]),
            Section::make(__('admin.inventory.outbound.sections.supply_blocker'))->schema([
                RepeatableEntry::make('procurementRequirements')->columns(4)->schema([
                    TextEntry::make('productVariant.sku')->label(__('admin.inventory.outbound.fields.product')),
                    TextEntry::make('required_base_quantity')->label(__('admin.inventory.outbound.fields.required'))->formatStateUsing(QuantityFormatter::display(...)),
                    TextEntry::make('fulfilled_base_quantity')->label(__('admin.inventory.outbound.fields.received'))->formatStateUsing(QuantityFormatter::display(...)),
                    TextEntry::make('status')->label(__('admin.inventory.outbound.fields.status'))->badge(),
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
