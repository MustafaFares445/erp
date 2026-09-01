<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockLevels\Actions;

use App\Data\Inventory\StockDamageData;
use App\Enums\InventoryPermission;
use App\Enums\MovementType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\InventoryLot;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Services\Inventory\InventoryDamageService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

final class StockDamageActions
{
    public static function damage(): Action
    {
        return self::make('damage', MovementType::Damage);
    }

    public static function recover(): Action
    {
        return self::make('recover_damage', MovementType::DamageRecovery);
    }

    public static function dispose(): Action
    {
        return self::make('dispose_damage', MovementType::Disposal);
    }

    private static function make(string $name, MovementType $operation): Action
    {
        return Action::make($name)
            ->label(self::label($operation))
            ->color(self::color($operation))
            ->visible(fn (InventoryStock $record): bool => self::isVisible($record, $operation))
            ->requiresConfirmation()
            ->schema([
                TextInput::make('quantity')
                    ->label(__('admin.inventory.damage.quantity'))
                    ->required()
                    ->numeric()
                    ->minValue(0.001)
                    ->maxValue(fn (InventoryStock $record): float => self::maximumQuantity($record, $operation))
                    ->step(0.001)
                    ->live()
                    ->hintIcon(Heroicon::QuestionMarkCircle, 'Enter the quantity affected. The maximum is limited to the stock available for this action.'),
                Select::make('inventory_lot_id')
                    ->label(__('admin.inventory.lot.fields.lot'))
                    ->options(fn (InventoryStock $record): array => self::lotOptions($record, $operation))
                    ->searchable()
                    ->preload()
                    ->visible(fn (InventoryStock $record): bool => self::tracksBatches($record))
                    ->required(fn (InventoryStock $record): bool => self::tracksBatches($record)),
                Select::make('serialized_inventory_unit_id')
                    ->label(__('admin.inventory.damage.serialized_unit'))
                    ->options(fn (InventoryStock $record): array => self::serializedOptions($record, $operation))
                    ->searchable()
                    ->hintIcon(Heroicon::QuestionMarkCircle, 'Select the individual serialized unit when the action applies to one tracked item.'),
                Textarea::make('reason')
                    ->label(__('admin.inventory.damage.reason'))
                    ->required()
                    ->maxLength(2_000)
                    ->hintIcon(Heroicon::QuestionMarkCircle, 'Explain the cause so the stock adjustment can be audited later.'),
                TextEntry::make('impact')
                    ->label(__('admin.inventory.damage.impact'))
                    ->state(fn (InventoryStock $record, Get $get): string => self::impact($record, $get, $operation)),
            ])
            ->action(function (array $data, InventoryStock $record) use ($operation): void {
                $actor = self::actor();
                $service = app(InventoryDamageService::class);
                $input = new StockDamageData(
                    quantity: self::number($data['quantity'] ?? null),
                    reason: is_string($data['reason'] ?? null) ? $data['reason'] : '',
                    serializedInventoryUnitId: self::nullableInteger($data['serialized_inventory_unit_id'] ?? null),
                    inventoryLotId: self::nullableInteger($data['inventory_lot_id'] ?? null),
                );

                self::ensureSupported($operation);

                if ($operation === MovementType::Damage) {
                    $service->damage($record, $input, $actor);
                } elseif ($operation === MovementType::DamageRecovery) {
                    $service->recover($record, $input, $actor);
                } else {
                    $service->dispose($record, $input, $actor);
                }

                Notification::make()
                    ->title(__('admin.inventory.notifications.success'))
                    ->success()
                    ->send();
            });
    }

    private static function isVisible(InventoryStock $stock, MovementType $operation): bool
    {
        $actor = auth()->user();

        if (! $actor instanceof User || ! self::canManage($actor)) {
            return false;
        }

        return self::maximumQuantity($stock, $operation) > 0;
    }

    private static function canManage(User $actor): bool
    {
        return $actor->can(InventoryPermission::StockView->value)
            && $actor->can(InventoryPermission::AdjustmentConfirm->value);
    }

    private static function maximumQuantity(InventoryStock $stock, MovementType $operation): float
    {
        return $operation === MovementType::Damage
            ? (float) $stock->available_quantity
            : (float) $stock->damaged_quantity;
    }

    private static function tracksBatches(InventoryStock $stock): bool
    {
        return ProductVariant::query()->with('product')->find($stock->product_variant_id)?->productType()?->tracksBatches() === true;
    }

    /** @return array<int, string> */
    private static function lotOptions(InventoryStock $stock, MovementType $operation): array
    {
        $condition = $operation === MovementType::Damage
            ? StockCondition::Saleable
            : StockCondition::Damaged;

        return InventoryLot::query()
            ->canonical()
            ->where('product_variant_id', $stock->product_variant_id)
            ->whereHas('conditionBalances', function (Builder $balance) use ($condition, $stock): void {
                $balance->where('warehouse_id', $stock->warehouse_id)
                    ->where('stock_condition', $condition->value)
                    ->where('on_hand_base_quantity', '>', 0);
            })
            ->orderByRaw('expires_at IS NULL')
            ->orderBy('expires_at')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(function (InventoryLot $lot): array {
                $lotKey = $lot->getKey();

                if (! is_int($lotKey)) {
                    throw new \LogicException('Inventory lot identifiers must be integers.');
                }

                return [$lotKey => $lot->lot_number ?? '#'.$lotKey];
            })
            ->all();
    }

    /** @return array<int, string> */
    private static function serializedOptions(InventoryStock $stock, MovementType $operation): array
    {
        $status = $operation === MovementType::Damage
            ? SerializedInventoryUnitStatus::Available
            : SerializedInventoryUnitStatus::Damaged;
        $condition = $operation === MovementType::Damage
            ? StockCondition::Saleable
            : StockCondition::Damaged;

        return SerializedInventoryUnit::query()
            ->where('product_variant_id', $stock->product_variant_id)
            ->where('warehouse_id', $stock->warehouse_id)
            ->where('status', $status->value)
            ->where('stock_condition', $condition->value)
            ->orderBy('serial_number')
            ->get(['id', 'serial_number', 'iot_number'])
            ->mapWithKeys(function (SerializedInventoryUnit $unit): array {
                $key = self::integerKey($unit);

                return [
                    $key => $unit->iot_number === null
                        ? $unit->serial_number
                        : sprintf('%s / %s', $unit->serial_number, $unit->iot_number),
                ];
            })
            ->all();
    }

    private static function impact(InventoryStock $stock, Get $get, MovementType $operation): string
    {
        $quantity = self::number($get('quantity'));
        $onHand = (float) $stock->on_hand_quantity;
        $damaged = (float) $stock->damaged_quantity;
        $available = (float) $stock->available_quantity;

        self::ensureSupported($operation);

        if ($operation === MovementType::Damage) {
            [$onHandAfter, $damagedAfter, $availableAfter] = [$onHand, $damaged + $quantity, $available - $quantity];
        } elseif ($operation === MovementType::DamageRecovery) {
            [$onHandAfter, $damagedAfter, $availableAfter] = [$onHand, $damaged - $quantity, $available + $quantity];
        } else {
            [$onHandAfter, $damagedAfter, $availableAfter] = [$onHand - $quantity, $damaged - $quantity, $available];
        }

        return sprintf(
            'On hand %.3f -> %.3f; damaged %.3f -> %.3f; available %.3f -> %.3f',
            $onHand,
            $onHandAfter,
            $damaged,
            $damagedAfter,
            $available,
            $availableAfter,
        );
    }

    private static function label(MovementType $operation): string
    {
        return match ($operation) {
            MovementType::Damage => __('admin.inventory.damage.mark'),
            MovementType::DamageRecovery => __('admin.inventory.damage.recover'),
            MovementType::Disposal => __('admin.inventory.damage.dispose'),
            default => $operation->name,
        };
    }

    private static function color(MovementType $operation): string
    {
        return match ($operation) {
            MovementType::Damage => 'warning',
            MovementType::DamageRecovery => 'success',
            MovementType::Disposal => 'danger',
            default => 'gray',
        };
    }

    private static function number(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private static function nullableInteger(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User || ! self::canManage($actor)) {
            throw new AuthorizationException;
        }

        return $actor;
    }

    private static function ensureSupported(MovementType $operation): void
    {
        throw_unless(
            in_array($operation, [MovementType::Damage, MovementType::DamageRecovery, MovementType::Disposal], true),
            \LogicException::class,
            'Unsupported stock damage action.',
        );
    }

    private static function integerKey(SerializedInventoryUnit $unit): int
    {
        $key = $unit->getKey();

        if (! is_int($key)) {
            throw new \LogicException('Serialized inventory unit keys must be integers.');
        }

        return $key;
    }
}
