<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseInbounds\Actions;

use App\Enums\InventoryPermission;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Models\PurchaseInboundLine;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Purchasing\PurchaseInboundService;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

final class PurchaseInboundAllocationActions
{
    public static function add(): Action
    {
        return Action::make('allocateQuantity')
            ->label(__('admin.logistics.inbound.allocation.allocate_short'))
            ->schema([
                Select::make('warehouse_id')
                    ->label(__('admin.logistics.inbound.allocation.warehouse'))
                    ->options(fn (PurchaseInboundLine $record): array => self::availableWarehouses($record))
                    ->searchable()->preload()->required(),
                TextInput::make('allocated_base_quantity')
                    ->label(__('admin.logistics.inbound.allocation.quantity'))
                    ->numeric()->step(0.000001)->minValue(0.000001)->required(),
            ])
            ->visible(fn (): bool => self::canAllocate())
            ->action(function (PurchaseInboundLine $record, array $data): void {
                $warehouse = Warehouse::query()->findOrFail(self::integerInput($data['warehouse_id'] ?? null));
                app(PurchaseInboundService::class)->allocate(
                    self::actor(),
                    $record,
                    $warehouse,
                    self::decimalInput($data['allocated_base_quantity'] ?? null),
                );

                Notification::make()->success()->title(__('admin.logistics.inbound.notifications.allocated'))->send();
            });
    }

    public static function edit(): Action
    {
        return Action::make('editAllocation')
            ->label(__('admin.logistics.inbound.allocation.edit'))
            ->schema([
                Select::make('allocation_id')
                    ->label(__('admin.logistics.inbound.allocation.allocation'))
                    ->options(fn (PurchaseInboundLine $record): array => self::allocationOptions($record))
                    ->required()->searchable()->preload(),
                Select::make('warehouse_id')
                    ->label(__('admin.logistics.inbound.allocation.warehouse'))
                    ->options(fn (): array => self::activeWarehouses())
                    ->required()->searchable()->preload(),
                TextInput::make('allocated_base_quantity')
                    ->label(__('admin.logistics.inbound.allocation.quantity'))
                    ->numeric()->step(0.000001)->minValue(0.000001)->required(),
            ])
            ->visible(fn (PurchaseInboundLine $record): bool => self::canAllocate() && $record->allocations()->exists())
            ->action(function (PurchaseInboundLine $record, array $data): void {
                $allocation = $record->allocations()->findOrFail(self::integerInput($data['allocation_id'] ?? null));
                $warehouse = Warehouse::query()->findOrFail(self::integerInput($data['warehouse_id'] ?? null));

                app(PurchaseInboundService::class)->updateAllocation(
                    self::actor(),
                    $record,
                    $allocation,
                    $warehouse,
                    self::decimalInput($data['allocated_base_quantity'] ?? null),
                );

                Notification::make()->success()->title(__('admin.logistics.inbound.notifications.updated'))->send();
            });
    }

    public static function remove(): Action
    {
        return Action::make('removeUnusedAllocation')
            ->label(__('admin.logistics.inbound.allocation.remove'))
            ->color('danger')
            ->requiresConfirmation()
            ->schema([
                Select::make('allocation_id')
                    ->label(__('admin.logistics.inbound.allocation.allocation'))
                    ->options(fn (PurchaseInboundLine $record): array => self::allocationOptions($record))
                    ->required()->searchable()->preload(),
            ])
            ->visible(fn (PurchaseInboundLine $record): bool => self::canAllocate() && $record->allocations()->exists())
            ->action(function (PurchaseInboundLine $record, array $data): void {
                $allocation = $record->allocations()->findOrFail(self::integerInput($data['allocation_id'] ?? null));
                app(PurchaseInboundService::class)->removeAllocation(self::actor(), $record, $allocation);
                Notification::make()->success()->title(__('admin.logistics.inbound.notifications.removed'))->send();
            });
    }

    public static function confirm(): Action
    {
        return Action::make('confirmAllocation')
            ->label(__('admin.logistics.inbound.allocation.confirm'))
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('admin.logistics.inbound.allocation.confirm_impact'))
            ->schema([
                Select::make('allocation_id')
                    ->label(__('admin.logistics.inbound.allocation.allocation'))
                    ->options(fn (PurchaseInboundLine $record): array => self::allocationOptions($record))
                    ->required()
                    ->searchable()
                    ->preload(),
            ])
            ->visible(fn (PurchaseInboundLine $record): bool => self::canAllocate() && $record->allocations()->exists())
            ->action(function (PurchaseInboundLine $record, array $data): void {
                $allocation = $record->allocations()->findOrFail(self::integerInput($data['allocation_id'] ?? null));
                $receipt = app(PurchaseOrderReceivingService::class)
                    ->ensureDraftReceiptForAllocation(self::actor(), $allocation);

                Notification::make()
                    ->success()
                    ->title(__('admin.logistics.inbound.notifications.draft_receipt_generated', ['number' => $receipt->operation_number]))
                    ->body(__('admin.logistics.inbound.notifications.continue_receiving'))
                    ->actions([
                        Action::make('openReceipt')
                            ->label(__('admin.logistics.inbound.allocation.open_receipt'))
                            ->url(InventoryOperationResource::getUrl('view', ['record' => $receipt])),
                    ])
                    ->send();
            });
    }

    private static function canAllocate(): bool
    {
        return auth()->user()?->can(InventoryPermission::InboundAllocate->value) ?? false;
    }

    private static function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated warehouse actor is required.');
        }

        return $actor;
    }

    /** @return array<int, string> */
    private static function availableWarehouses(PurchaseInboundLine $line): array
    {
        $used = $line->allocations()->pluck('warehouse_id');

        return Warehouse::query()
            ->where('is_active', true)
            ->when($used->isNotEmpty(), static fn (Builder $query): Builder => $query->whereNotIn('id', $used))
            ->orderBy('name')->pluck('name', 'id')
            ->mapWithKeys(static fn (mixed $name, mixed $id): array => [self::integerInput($id) => self::stringInput($name)])
            ->all();
    }

    /** @return array<int, string> */
    private static function activeWarehouses(): array
    {
        return Warehouse::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')
            ->mapWithKeys(static fn (mixed $name, mixed $id): array => [self::integerInput($id) => self::stringInput($name)])
            ->all();
    }

    /** @return array<int, string> */
    private static function allocationOptions(PurchaseInboundLine $line): array
    {
        $options = [];

        foreach ($line->allocations()->with('warehouse')->orderBy('id')->get() as $allocation) {
            $options[$allocation->id] = __('admin.logistics.inbound.phrases.allocation_summary', [
                'warehouse' => $allocation->warehouse->name,
                'allocated' => $allocation->allocated_base_quantity ?? '0.000000',
                'received' => $allocation->receivedBaseQuantity(),
            ]);
        }

        return $options;
    }

    private static function integerInput(mixed $value): int
    {
        if (! is_numeric($value)) {
            throw new LogicException('A numeric allocation identifier is required.');
        }

        return (int) $value;
    }

    private static function decimalInput(mixed $value): string
    {
        if (! is_numeric($value)) {
            throw new LogicException('A numeric allocation quantity is required.');
        }

        return (string) $value;
    }

    private static function stringInput(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new LogicException('A scalar warehouse label is required.');
        }

        return (string) $value;
    }
}
