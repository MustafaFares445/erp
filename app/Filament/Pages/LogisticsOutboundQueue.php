<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\InventoryPermission;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Inventory\InventoryOperationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

final class LogisticsOutboundQueue extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.outbound_fulfillment');
    }

    #[\Override]
    public function getTitle(): string
    {
        return __('admin.resources.outbound_fulfillment');
    }

    #[\Override]
    public static function canAccess(): bool
    {
        return auth()->user()?->can(InventoryPermission::DeliveryView->value) ?? false;
    }

    #[\Override]
    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(InventoryOperation::query()
                ->where('operation_type', OperationType::Delivery->value)
                ->with([
                    'customer',
                    'sourceWarehouse',
                    'lines.productVariant',
                    'sourceDocument',
                ])
                ->withSum([
                    'reservations as active_reserved_base_quantity' => static fn (Builder $query): Builder => $query
                        ->where('status', 'active'),
                ], 'base_quantity'))
            ->defaultSort('scheduled_at')
            ->columns([
                TextColumn::make('operation_number')->label(__('admin.resources.inventory_deliveries'))->placeholder(__('admin.operation.stages.draft'))->searchable(),
                TextColumn::make('source_order')->label(__('admin.logistics.fields.source_sales_order'))
                    ->state(fn (InventoryOperation $record): string => self::sourceOrder($record)),
                TextColumn::make('customer.company_name')->label(__('admin.operation.fields.customer'))->searchable(),
                TextColumn::make('sourceWarehouse.name')->label(__('admin.operation.fields.source_warehouse'))->searchable(),
                TextColumn::make('scheduled_at')->label(__('admin.logistics.fields.required_date'))->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('products')->label(__('admin.operation.fields.product'))
                    ->state(fn (InventoryOperation $record): array => $record->lines
                        ->map(static fn (InventoryOperationLine $line): string => $line->productVariant instanceof ProductVariant
                            ? $line->productVariant->sku
                            : '—')->unique()->values()->all())
                    ->listWithLineBreaks(),
                TextColumn::make('reserved')->label(__('admin.resources.reservations'))
                    ->state(function (InventoryOperation $record): string {
                        $reservedQuantity = $record->active_reserved_base_quantity ?? null;

                        return is_numeric($reservedQuantity) ? (string) $reservedQuantity : '0.000000';
                    }),
                TextColumn::make('picked')->label(__('admin.operation.fields.picked'))
                    ->state(fn (InventoryOperation $record): string => $record->lines->where('is_picked', true)->count().'/'.$record->lines->count()),
                TextColumn::make('stage')->label(__('admin.crm.fields.status'))->badge()
                    ->formatStateUsing(fn (OperationStage $state, InventoryOperation $record): string => $record->stageLabel()),
                TextColumn::make('next_action')->label(__('admin.logistics.fields.next_action'))
                    ->state(fn (InventoryOperation $record): string => self::nextAction($record)),
            ])
            ->filters([
                SelectFilter::make('stage')->options(collect(OperationStage::cases())
                    ->mapWithKeys(static fn (OperationStage $stage): array => [$stage->value => $stage->label()])->all()),
                SelectFilter::make('source_warehouse_id')->relationship('sourceWarehouse', 'name')->searchable()->preload(),
            ])
            ->recordActions(self::actions());
    }

    /** @return list<Action> */
    private static function actions(): array
    {
        return [
            Action::make('viewDelivery')->label(__('admin.actions.view'))
                ->url(fn (InventoryOperation $record): string => InventoryOperationResource::getUrl('view', ['record' => $record])),
            Action::make('prepare')->label(__('admin.logistics.actions.prepare'))
                ->visible(fn (InventoryOperation $record): bool => $record->isDraft()
                    && (auth()->user()?->can('update', $record) ?? false))
                ->url(fn (InventoryOperation $record): string => InventoryOperationResource::getUrl('edit', ['record' => $record])),
            Action::make('pick')->label(__('admin.logistics.actions.pick_lines'))
                ->visible(fn (InventoryOperation $record): bool => ! $record->stage->isTerminal()
                    && (auth()->user()?->can('update', $record) ?? false))
                ->url(fn (InventoryOperation $record): string => InventoryOperationResource::getUrl('edit', ['record' => $record])),
            ...self::lifecycleActions(),
        ];
    }

    /** @return list<Action> */
    private static function lifecycleActions(): array
    {
        return [
            Action::make('markReady')->label(__('admin.logistics.actions.mark_ready'))
                ->visible(fn (InventoryOperation $record): bool => self::allPicked($record)
                    && (auth()->user()?->can('markReady', $record) ?? false))
                ->action(function (InventoryOperation $record): void {
                    app(InventoryOperationService::class)->markReady($record, self::actor());
                    Notification::make()->success()->title(__('admin.logistics.notifications.delivery_ready'))->send();
                }),
            Action::make('dispatch')->label(__('admin.logistics.actions.dispatch_complete'))->color('success')
                ->requiresConfirmation()
                ->visible(fn (InventoryOperation $record): bool => auth()->user()?->can('complete', $record) ?? false)
                ->action(function (InventoryOperation $record): void {
                    app(InventoryOperationService::class)->complete($record, self::actor());
                    Notification::make()->success()->title(__('admin.logistics.notifications.delivery_completed'))->send();
                }),
        ];
    }

    private static function sourceOrder(InventoryOperation $operation): string
    {
        return $operation->sourceDocument instanceof Order
            ? $operation->sourceDocument->order_number
            : '—';
    }

    private static function allPicked(InventoryOperation $operation): bool
    {
        return $operation->lines->isNotEmpty()
            && $operation->lines->every(static fn (InventoryOperationLine $line): bool => $line->is_picked);
    }

    private static function nextAction(InventoryOperation $operation): string
    {
        return match ($operation->stage) {
            OperationStage::Draft => self::allPicked($operation)
                ? __('admin.logistics.actions.mark_ready')
                : __('admin.logistics.actions.prepare_pick'),
            OperationStage::Waiting => __('admin.logistics.actions.resolve_stock_shortage'),
            OperationStage::Ready => __('admin.logistics.actions.dispatch_complete'),
            OperationStage::Done => __('admin.logistics.actions.completed'),
            OperationStage::Canceled => __('admin.operation.stages.canceled'),
            default => __('admin.logistics.actions.review_delivery'),
        };
    }

    private static function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated warehouse actor is required.');
        }

        return $actor;
    }
}
