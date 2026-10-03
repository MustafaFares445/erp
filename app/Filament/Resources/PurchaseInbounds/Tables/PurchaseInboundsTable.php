<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseInbounds\Tables;

use App\Data\Inventory\LogisticsInboundBlockerData;
use App\Data\Inventory\LogisticsInboundData;
use App\Enums\PurchaseInboundStatus;
use App\Filament\Resources\PurchaseInbounds\Actions\PurchaseInboundActions;
use App\Filament\Tables\Columns\FavoriteColumn;
use App\Filament\Tables\Filters\TableQueryBuilder;
use App\Models\PurchaseInbound;
use App\Services\Inventory\LogisticsInboundProjectionService;
use App\Support\QuantityFormatter;
use Filament\Actions\ViewAction;
use Filament\QueryBuilder\Constraints\DateConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\QueryBuilder\Constraints\SelectConstraint;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use WeakMap;

final class PurchaseInboundsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                FavoriteColumn::make(),
                TextColumn::make('id')->label(__('admin.logistics.inbound.number'))->prefix('INB-')->sortable(),
                TextColumn::make('purchaseOrder.purchase_order_number')
                    ->label(__('admin.logistics.inbound.purchase_order_reference'))
                    ->description(fn (PurchaseInbound $record): ?string => self::blockerMessage($record))
                    ->searchable()->sortable(),
                TextColumn::make('purchaseOrder.supplier.name')
                    ->label(__('admin.logistics.inbound.supplier'))->searchable(),
                TextColumn::make('purchaseOrder.expected_at')
                    ->label(__('admin.logistics.inbound.expected_date'))->date()->placeholder(__('—'))->sortable(),
                TextColumn::make('business_state')
                    ->label(__('admin.logistics.inbound.business_state'))
                    ->getStateUsing(fn (PurchaseInbound $record): string => __(sprintf(
                        'admin.logistics.inbound.states.%s',
                        str(self::projection($record)->businessState)->replace(' / ', ' ')->snake()->toString(),
                    )))
                    ->badge()
                    ->color(fn (PurchaseInbound $record): string => self::stateColor(self::projection($record)->businessState)),
                TextColumn::make('confirmed_qty')
                    ->label(__('admin.logistics.inbound.confirmed'))
                    ->getStateUsing(fn (PurchaseInbound $record): string => QuantityFormatter::display(self::projection($record)->confirmedBaseQuantity)),
                TextColumn::make('allocated_qty')
                    ->label(__('admin.logistics.inbound.allocated'))
                    ->getStateUsing(fn (PurchaseInbound $record): string => QuantityFormatter::display(self::projection($record)->allocatedBaseQuantity)),
                TextColumn::make('received_qty')
                    ->label(__('admin.logistics.inbound.received'))
                    ->getStateUsing(fn (PurchaseInbound $record): string => QuantityFormatter::display(self::projection($record)->receivedBaseQuantity)),
                TextColumn::make('remaining_qty')
                    ->label(__('admin.logistics.inbound.remaining'))
                    ->getStateUsing(fn (PurchaseInbound $record): string => QuantityFormatter::display(self::projection($record)->remainingBaseQuantity)),
                TextColumn::make('warehouses')
                    ->label(__('admin.logistics.inbound.destination_warehouses'))
                    ->getStateUsing(fn (PurchaseInbound $record): array => self::projection($record)->destinationWarehouses)
                    ->listWithLineBreaks()->placeholder(__('—')),
                IconColumn::make('overdue')
                    ->label(__('admin.logistics.inbound.overdue'))
                    ->boolean()
                    ->getStateUsing(fn (PurchaseInbound $record): bool => self::projection($record)->overdue),
                TextColumn::make('blocker')
                    ->label(__('admin.logistics.inbound.blocker'))
                    ->getStateUsing(fn (PurchaseInbound $record): ?string => self::blockerMessage($record))
                    ->placeholder(__('admin.logistics.inbound.none'))
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('next_action')
                    ->label(__('admin.logistics.fields.next_action'))
                    ->getStateUsing(fn (PurchaseInbound $record): string => __(sprintf(
                        'admin.logistics.inbound.next_actions.%s',
                        str(self::projection($record)->nextAction)->snake()->toString(),
                    ))),
            ])
            ->groups([
                Group::make('status')->label(__('Status')),
                Group::make('purchaseOrder.supplier.name')->label(__('admin.logistics.inbound.supplier')),
                Group::make('purchaseOrder.expected_at')->label(__('admin.logistics.inbound.expected_date'))->date(),
            ])
            ->filters([
                TableQueryBuilder::make([
                    SelectConstraint::make('status')
                        ->label(__('Status'))
                        ->options(PurchaseInboundStatus::class)
                        ->multiple(),
                    RelationshipConstraint::make('purchaseOrder')
                        ->label(__('admin.logistics.inbound.purchase_order_reference'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('purchase_order_number')->searchable()->multiple()),
                    DateConstraint::make('activated_at')->label(__('Activated at')),
                    DateConstraint::make('completed_at')->label(__('Completed at')),
                    DateConstraint::make('created_at')->label(__('Created at')),
                ]),
                Filter::make('overdue')
                    ->query(static fn (Builder $query): Builder => $query
                        ->whereHas('purchaseOrder', static fn (Builder $po): Builder => $po->whereDate('expected_at', '<', today()))
                        ->where('status', '!=', PurchaseInboundStatus::Received->value)),
            ])
            ->recordActions([
                PurchaseInboundActions::allocate()
                    ->label(__('Allocate stock'))
                    ->icon(Heroicon::BuildingStorefront)
                    ->button()
                    ->visible(fn (PurchaseInbound $record): bool => self::primaryAction($record) === 'allocate'),
                PurchaseInboundActions::createOrOpenReceipt()
                    ->icon(Heroicon::InboxArrowDown)
                    ->button()
                    ->visible(fn (PurchaseInbound $record): bool => self::primaryAction($record) === 'receive'),
                ViewAction::make(),
            ]);
    }

    /**
     * The single next valid action for the inbound, or null when the record is
     * terminal or the user is not authorised for it.
     */
    private static function primaryAction(PurchaseInbound $record): ?string
    {
        $state = self::projection($record)->businessState;

        if (! in_array($state, ['Awaiting Allocation', 'Ready to Receive', 'Partially Received'], true)) {
            return null;
        }

        if ($state === 'Awaiting Allocation' && PurchaseInboundActions::canAllocate($record)) {
            return 'allocate';
        }

        if (PurchaseInboundActions::canReceive($record)) {
            return 'receive';
        }

        return PurchaseInboundActions::canAllocate($record) ? 'allocate' : null;
    }

    private static function blockerMessage(PurchaseInbound $record): ?string
    {
        $blocker = self::projection($record)->blockers[0] ?? null;

        return $blocker instanceof LogisticsInboundBlockerData
            ? (string) __('admin.logistics.inbound.blocker_messages.'.$blocker->code)
            : null;
    }

    private static function projection(PurchaseInbound $record): LogisticsInboundData
    {
        /** @var WeakMap<PurchaseInbound, LogisticsInboundData>|null $cache */
        static $cache = null;

        $cache ??= new WeakMap;

        $cached = $cache[$record] ?? null;

        if ($cached instanceof LogisticsInboundData) {
            return $cached;
        }

        $projection = app(LogisticsInboundProjectionService::class)->project($record);
        $cache[$record] = $projection;

        return $projection;
    }

    private static function stateColor(string $state): string
    {
        return match ($state) {
            'Awaiting Allocation' => 'warning',
            'Ready to Receive' => 'info',
            'Partially Received' => 'primary',
            'Received' => 'success',
            'Cancelled / Closed' => 'gray',
            default => 'gray',
        };
    }
}
