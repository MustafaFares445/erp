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
    case ReturnView = 'inventory.return.view';
    case ReturnCreate = 'inventory.return.create';
    case ReturnInspect = 'inventory.return.inspect';
    case ReturnPost = 'inventory.return.post';
    case ReturnCancel = 'inventory.return.cancel';
    case CatalogView = 'inventory.catalog.view';
    case CatalogManage = 'inventory.catalog.manage';
    case ReceiptView = 'inventory.receipt.view';
    case ReceiptCreate = 'inventory.receipt.create';
    case ReceiptConfirm = 'inventory.receipt.confirm';
    case DeliveryView = 'inventory.delivery.view';
    case DeliveryCreate = 'inventory.delivery.create';
    case DeliveryConfirm = 'inventory.delivery.confirm';
    case ShipmentView = 'inventory.shipment.view';
    case ShipmentConfirm = 'inventory.shipment.confirm';
    case PricingView = 'inventory.pricing.view';
    case PricingManage = 'inventory.pricing.manage';
    case PricingReview = 'inventory.pricing.review';
    case PriceFloorApprove = 'inventory.price-floor.approve';
    case ImportManage = 'inventory.import.manage';
    case ReportView = 'inventory.report.view';
    case Export = 'inventory.export';
    case AlertView = 'inventory.alert.view';
    case PackageView = 'inventory.package.view';
    case PackageManage = 'inventory.package.manage';

    /**
     * Permits releasing an expired lot into an outbound operation. Expired stock is otherwise
     * blocked outright; every use of this override writes an alert and an audit entry.
     */
    case ExpiredStockOverride = 'inventory.expired-stock.override';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $permission): string => $permission->value, self::cases());
    }
}
