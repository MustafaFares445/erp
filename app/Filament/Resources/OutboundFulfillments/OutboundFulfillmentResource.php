<?php

declare(strict_types=1);

namespace App\Filament\Resources\OutboundFulfillments;

use App\Enums\InventoryPermission;
use App\Enums\OrderStatus;
use App\Filament\Components\InventoryViewDiagnostics;
use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\OutboundFulfillments\Actions\OutboundFulfillmentActions;
use App\Filament\Resources\OutboundFulfillments\Pages\ListOutboundFulfillments;
use App\Filament\Resources\OutboundFulfillments\Pages\ViewOutboundFulfillment;
use App\Filament\Resources\Shipments\ShipmentResource;
use App\Filament\Tables\TableFit;
use App\Models\InventoryOperation;
use App\Models\Order;
use App\Models\OrderLine;
use App\Services\Sales\OrderWorkflowProjectionStore;
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
use Illuminate\Support\Str;
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
        return TableFit::apply($table)
            ->defaultSort('scheduled_at')
            ->columns([
                TextColumn::make('order_number')
                    ->label(__('admin.inventory.outbound.fields.order'))
                    ->description(fn (Order $record): ?string => self::blockerSummary($record))
                    ->tooltip(fn (Order $record): ?string => self::blockerMessage($record))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer.company_name')->wrap()->label(__('admin.inventory.outbound.fields.customer'))->searchable(),
                TextColumn::make('scheduled_at')->label(__('admin.inventory.outbound.fields.requested_date'))->date()->sortable(),
                TextColumn::make('logistics_milestone')
                    ->label(__('admin.inventory.outbound.fields.milestone'))
                    ->state(fn (Order $record): string => self::milestoneLabel($record))
                    ->badge(),
                TextColumn::make('remaining')
                    ->label(__('admin.inventory.outbound.fields.remaining'))
                    ->state(fn (Order $record): string => QuantityFormatter::display(app(OrderWorkflowProjectionStore::class)->project($record)->remainingBase)),
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
            ->recordActions(TableFit::rowActions([
                OutboundFulfillmentActions::reviewSupply(),
                OutboundFulfillmentActions::createDeliveryPlan(tableRow: true),
                OutboundFulfillmentActions::prepareDelivery(tableRow: true),
                OutboundFulfillmentActions::dispatchGoods(tableRow: true),
                OutboundFulfillmentActions::confirmArrival(tableRow: true),
                ViewAction::make(),
            ]));
    }

    private static function blockerSummary(Order $record): ?string
    {
        $message = self::blockerMessage($record);

        return $message === null ? null : Str::limit($message, 80);
    }

    /**
     * The workflow projection stores English source strings; they are
     * translated here, at the display boundary only.
     */
    private static function blockerMessage(Order $record): ?string
    {
        $message = app(OrderWorkflowProjectionStore::class)->project($record)->blockerMessage;

        return $message === null ? null : (string) __($message);
    }

    private static function milestoneLabel(Order $record): string
    {
        return __(app(OrderWorkflowProjectionStore::class)->project($record)->businessMilestone);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            InventoryViewDiagnostics::section(),
            InventoryViewDiagnostics::metrics(static fn (Order $record): array => self::metrics($record)),
            Section::make(__('admin.inventory.outbound.sections.demand'))->columnSpanFull()->columns(['default' => 1, 'md' => 2, 'xl' => 4])->schema([
                TextEntry::make('order_number')
                    ->label(__('admin.inventory.outbound.fields.order'))
                    ->url(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record])),
                TextEntry::make('customer.company_name')->label(__('admin.inventory.outbound.fields.customer')),
                TextEntry::make('scheduled_at')->label(__('admin.inventory.outbound.fields.requested_date'))->date()->placeholder(__('—')),
                TextEntry::make('milestone')->label(__('admin.inventory.outbound.fields.milestone'))->hintIcon(Heroicon::QuestionMarkCircle, __('inventory_view_explanations.milestone'))->state(fn (Order $record): string => self::milestoneLabel($record))->badge(),
            ]),
            Section::make(__('admin.inventory.outbound.sections.demand_lines'))->columnSpanFull()->schema([
                RepeatableEntry::make('lines')->columns(['default' => 1, 'md' => 2, 'xl' => 4])->schema([
                    TextEntry::make('productVariant.sku')->label(__('admin.inventory.outbound.fields.product')),
                    TextEntry::make('base_quantity')
                        ->label(__('admin.inventory.outbound.fields.requested'))
                        ->state(fn (OrderLine $record): string => QuantityFormatter::display($record->base_quantity)),
                    TextEntry::make('short_closed_base_quantity')
                        ->label(__('admin.inventory.outbound.fields.short_closed'))
                        ->hintIcon(Heroicon::QuestionMarkCircle, __('inventory_view_explanations.short_closed'))
                        ->state(fn (OrderLine $record): string => QuantityFormatter::display($record->short_closed_base_quantity)),
                    TextEntry::make('unit.name')->label(__('admin.inventory.outbound.fields.commercial_uom'))->placeholder(__('—')),
                ]),
            ]),
            Section::make(__('admin.inventory.outbound.sections.planned_deliveries'))->columnSpanFull()->schema([
                RepeatableEntry::make('deliveries')->columns(['default' => 1, 'md' => 2, 'xl' => 5])->schema([
                    TextEntry::make('operation_number')
                        ->label(__('admin.inventory.outbound.fields.delivery'))
                        ->placeholder(__('admin.inventory.outbound.placeholders.draft'))
                        ->url(fn (InventoryOperation $record): string => InventoryOperationResource::getUrl('view', ['record' => $record])),
                    TextEntry::make('sourceWarehouse.name')->label(__('admin.inventory.outbound.fields.warehouse'))->hintIcon(Heroicon::QuestionMarkCircle, __('inventory_view_explanations.warehouse')),
                    TextEntry::make('stage')->badge()->formatStateUsing(fn (mixed $state, InventoryOperation $record): string => $record->stageLabel()),
                    TextEntry::make('shipment.tracking_number')
                        ->label(__('admin.inventory.outbound.fields.tracking'))
                        ->hintIcon(Heroicon::QuestionMarkCircle, __('inventory_view_explanations.tracking'))
                        ->placeholder(__('—'))
                        ->url(fn (InventoryOperation $record): ?string => $record->shipment
                            ? ShipmentResource::getUrl('view', ['record' => $record->shipment])
                            : null),
                    TextEntry::make('shipment.status')->label(__('admin.inventory.outbound.fields.shipment'))->hintIcon(Heroicon::QuestionMarkCircle, __('inventory_view_explanations.status'))->badge()->placeholder(__('—')),
                ]),
            ]),
            Section::make(__('admin.inventory.outbound.sections.supply_blocker'))
                ->columnSpanFull()
                ->visible(static fn (Order $record): bool => $record->procurementRequirements->isNotEmpty())
                ->schema([
                    RepeatableEntry::make('procurementRequirements')->columns(['default' => 1, 'md' => 2, 'xl' => 4])->schema([
                        TextEntry::make('productVariant.sku')->label(__('admin.inventory.outbound.fields.product')),
                        TextEntry::make('required_base_quantity')->label(__('admin.inventory.outbound.fields.required'))->formatStateUsing(QuantityFormatter::display(...)),
                        TextEntry::make('fulfilled_base_quantity')->label(__('admin.inventory.outbound.fields.received'))->formatStateUsing(QuantityFormatter::display(...)),
                        TextEntry::make('status')->label(__('admin.inventory.outbound.fields.status'))->badge()
                            ->formatStateUsing(static fn (string $state): string => __(ucfirst($state))),
                    ]),
                ]),
        ]);
    }

    /**
     * @return list<array{label: string, value: string, helper?: string|null, tone?: string|null}>
     */
    private static function metrics(Order $record): array
    {
        $projection = app(OrderWorkflowProjectionStore::class)->project($record);

        return [
            ['label' => __('admin.inventory.outbound.fields.requested'), 'value' => QuantityFormatter::display($projection->requestedBase)],
            ['label' => __('admin.inventory.outbound.fields.planned'), 'value' => QuantityFormatter::display($projection->plannedBase), 'tone' => 'primary'],
            ['label' => __('admin.inventory.outbound.fields.ready'), 'value' => QuantityFormatter::display($projection->readyBase)],
            ['label' => __('inventory_view_ux.ui.outbound_dispatched'), 'value' => QuantityFormatter::display($projection->dispatchedBase)],
            [
                'label' => __('admin.inventory.outbound.fields.remaining'),
                'value' => QuantityFormatter::display($projection->remainingBase),
                'tone' => round($projection->remainingBase, 6) > 0.0 ? 'warning' : null,
            ],
            [
                'label' => __('inventory_view_ux.ui.outbound_supply_outstanding'),
                'value' => QuantityFormatter::display($projection->procurementOutstandingBase),
                'tone' => $projection->procurementOutstandingBase > 0.000001 ? 'danger' : null,
            ],
        ];
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('status', OrderStatus::Released->value)
            ->with([
                'customer', 'lines.productVariant', 'lines.unit',
                'deliveries.sourceWarehouse', 'deliveries.shipment',
                'deliveries.lines.returnLines.inventoryReturn',
                'procurementRequirements.productVariant', 'shipments',
                'invoices.lines', 'invoices.writeOffs',
                'workflowPaymentTransactions.payment.allocations',
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
