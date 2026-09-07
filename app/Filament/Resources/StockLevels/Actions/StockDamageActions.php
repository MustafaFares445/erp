<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockLevels\Actions;

use App\Enums\InventoryConditionChangeType;
use App\Enums\InventoryPermission;
use App\Filament\Resources\InventoryConditionChanges\InventoryConditionChangeResource;
use App\Models\InventoryStock;
use App\Models\User;
use App\Services\Inventory\InventoryConditionChangeService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * Damage, recovery, and disposal no longer post directly from the stock row:
 * they open a prefilled {@see InventoryConditionChangeResource} create form so
 * every condition change becomes a document with a lifecycle (GAP-UI-06).
 * The canonical posting logic is reached only through
 * {@see InventoryConditionChangeService}'s post().
 */
final class StockDamageActions
{
    public static function damage(): Action
    {
        return self::make('damage', InventoryConditionChangeType::Damage, __('admin.inventory.damage.mark'), 'warning');
    }

    public static function recover(): Action
    {
        return self::make('recover_damage', InventoryConditionChangeType::DamageRecovery, __('admin.inventory.damage.recover'), 'success');
    }

    public static function dispose(): Action
    {
        return self::make('dispose_damage', InventoryConditionChangeType::Disposal, __('admin.inventory.damage.dispose'), 'danger');
    }

    private static function make(string $name, InventoryConditionChangeType $type, string $label, string $color): Action
    {
        return Action::make($name)
            ->label($label)
            ->color($color)
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->visible(fn (InventoryStock $record): bool => self::isVisible($record, $type))
            ->url(fn (InventoryStock $record): string => self::createUrl($record, $type));
    }

    private static function isVisible(InventoryStock $stock, InventoryConditionChangeType $type): bool
    {
        $actor = auth()->user();

        if (! $actor instanceof User || ! self::canManage($actor)) {
            return false;
        }

        return self::maximumQuantity($stock, $type) > 0;
    }

    private static function canManage(User $actor): bool
    {
        return $actor->can(InventoryPermission::StockView->value)
            && $actor->can(InventoryPermission::ConditionChangeCreate->value);
    }

    private static function maximumQuantity(InventoryStock $stock, InventoryConditionChangeType $type): float
    {
        return $type === InventoryConditionChangeType::Damage
            ? (float) $stock->available_quantity
            : (float) $stock->damaged_quantity;
    }

    private static function createUrl(InventoryStock $stock, InventoryConditionChangeType $type): string
    {
        return InventoryConditionChangeResource::getUrl('create', [
            'type' => $type->value,
            'product_variant_id' => $stock->product_variant_id,
            'warehouse_id' => $stock->warehouse_id,
        ]);
    }
}
