<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseInbounds\Actions;

use App\Enums\InventoryPermission;
use App\Models\PurchaseInboundLine;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Purchasing\PurchaseInboundService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use LogicException;

final class PurchaseInboundAllocationActions
{
    public static function add(): Action
    {
        return Action::make('allocateQuantity')
            ->label('Allocate Quantity')
            ->schema([
                Select::make('warehouse_id')
                    ->label('Warehouse')
                    ->options(fn (PurchaseInboundLine $record): array => self::availableWarehouses($record))
                    ->searchable()->preload()->required(),
                TextInput::make('allocated_base_quantity')
                    ->label('Quantity')
                    ->numeric()->step(0.000001)->minValue(0.000001)->required(),
            ])
            ->visible(fn (): bool => self::canAllocate())
            ->action(function (PurchaseInboundLine $record, array $data): void {
                $warehouse = Warehouse::query()->findOrFail((int) $data['warehouse_id']);
                app(PurchaseInboundService::class)->allocate(
                    self::actor(),
                    $record,
                    $warehouse,
                    (string) $data['allocated_base_quantity'],
                );

                Notification::make()->success()->title('Inbound quantity allocated')->send();
            });
    }

    public static function edit(): Action
    {
        return Action::make('editAllocation')
            ->label('Edit Allocation')
            ->schema([
                Select::make('allocation_id')
                    ->label('Allocation')
                    ->options(fn (PurchaseInboundLine $record): array => self::allocationOptions($record))
                    ->required()->searchable()->preload(),
                Select::make('warehouse_id')
                    ->label('Warehouse')
                    ->options(fn (): array => self::activeWarehouses())
                    ->required()->searchable()->preload(),
                TextInput::make('allocated_base_quantity')
                    ->label('Quantity')
                    ->numeric()->step(0.000001)->minValue(0.000001)->required(),
            ])
            ->visible(fn (PurchaseInboundLine $record): bool => self::canAllocate() && $record->allocations()->exists())
            ->action(function (PurchaseInboundLine $record, array $data): void {
                $allocation = $record->allocations()->findOrFail((int) $data['allocation_id']);
                $warehouse = Warehouse::query()->findOrFail((int) $data['warehouse_id']);

                app(PurchaseInboundService::class)->updateAllocation(
                    self::actor(),
                    $record,
                    $allocation,
                    $warehouse,
                    (string) $data['allocated_base_quantity'],
                );

                Notification::make()->success()->title('Inbound allocation updated')->send();
            });
    }

    public static function remove(): Action
    {
        return Action::make('removeUnusedAllocation')
            ->label('Remove Unused Allocation')
            ->color('danger')
            ->requiresConfirmation()
            ->schema([
                Select::make('allocation_id')
                    ->label('Allocation')
                    ->options(fn (PurchaseInboundLine $record): array => self::allocationOptions($record))
                    ->required()->searchable()->preload(),
            ])
            ->visible(fn (PurchaseInboundLine $record): bool => self::canAllocate() && $record->allocations()->exists())
            ->action(function (PurchaseInboundLine $record, array $data): void {
                $allocation = $record->allocations()->findOrFail((int) $data['allocation_id']);
                app(PurchaseInboundService::class)->removeAllocation(self::actor(), $record, $allocation);
                Notification::make()->success()->title('Unused allocation removed')->send();
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
            ->when($used->isNotEmpty(), static fn ($query) => $query->whereNotIn('id', $used))
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<int, string> */
    private static function activeWarehouses(): array
    {
        return Warehouse::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<int, string> */
    private static function allocationOptions(PurchaseInboundLine $line): array
    {
        $options = [];

        foreach ($line->allocations()->with('warehouse')->orderBy('id')->get() as $allocation) {
            $options[$allocation->id] = sprintf(
                '%s — allocated %s / received %s',
                $allocation->warehouse->name,
                $allocation->allocated_base_quantity ?? '0.000000',
                $allocation->receivedBaseQuantity(),
            );
        }

        return $options;
    }
}
