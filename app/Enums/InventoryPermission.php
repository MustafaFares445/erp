<?php

declare(strict_types=1);

namespace App\Enums;

use App\Policies\Concerns\ChecksInventoryPermissions;
use Database\Seeders\InventoryPermissionSeeder;

/**
 * Canonical `inventory.*` permission catalogue (guard: `web`).
 *
 * Single source of truth consumed by {@see InventoryPermissionSeeder}
 * and by {@see ChecksInventoryPermissions} so the
 * dashboard and any other access channel share identical permission names.
 *
 * @see /specs/001-inventory-dashboard-foundation/contracts/permissions.md
 */
enum InventoryPermission: string
{
    case WarehouseView = 'inventory.warehouse.view';
    case WarehouseManage = 'inventory.warehouse.manage';
    case StockView = 'inventory.stock.view';
    case MovementView = 'inventory.movement.view';
    case AdjustmentView = 'inventory.adjustment.view';
    case AdjustmentCreate = 'inventory.adjustment.create';
    case AdjustmentConfirm = 'inventory.adjustment.confirm';
    case TransferView = 'inventory.transfer.view';
    case TransferCreate = 'inventory.transfer.create';
    case TransferConfirm = 'inventory.transfer.confirm';
    case ReservationView = 'inventory.reservation.view';
    case ReservationRelease = 'inventory.reservation.release';
    case Export = 'inventory.export';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $permission): string => $permission->value, self::cases());
    }
}
