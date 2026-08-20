<?php

declare(strict_types=1);

namespace App\Enums;

use App\Policies\Concerns\ChecksPurchasePermissions;
use Database\Seeders\PurchasePermissionSeeder;

/**
 * Canonical `purchase.*` permission catalogue (guard: `web`).
 *
 * Single source of truth consumed by {@see PurchasePermissionSeeder} and by
 * {@see ChecksPurchasePermissions}, so the dashboard and any other access
 * channel share identical permission names.
 *
 * {@see self::OrderReceive} is deliberately its own permission rather than an
 * alias of any `inventory.*` one. FR-008 requires a purchasing user to receive
 * against a purchase order **without** gaining access to Inventory Operations,
 * Adjustments, or Stock Levels — which sharing a namespace would make
 * impossible.
 *
 * @see /specs/017-purchasing-orders-suppliers/contracts/permissions.md §1
 */
enum PurchasePermission: string
{
    case OrderView = 'purchase.order.view';
    case OrderManage = 'purchase.order.manage';
    case OrderSubmit = 'purchase.order.submit';
    case OrderApprove = 'purchase.order.approve';
    case OrderSend = 'purchase.order.send';
    case OrderCancel = 'purchase.order.cancel';
    case OrderClose = 'purchase.order.close';
    case OrderReceive = 'purchase.order.receive';
    case ConfirmationView = 'purchase.confirmation.view';
    case ConfirmationRecord = 'purchase.confirmation.record';
    case SupplierView = 'purchase.supplier.view';
    case SupplierManage = 'purchase.supplier.manage';
    case ProductReferenceView = 'purchase.product-reference.view';
    case ProductReferenceManage = 'purchase.product-reference.manage';
    case SettingManage = 'purchase.setting.manage';
    case RecordRestore = 'purchase.record.restore';
    case ReportView = 'purchase.report.view';
    case AuditView = 'purchase.audit.view';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $permission): string => $permission->value, self::cases());
    }
}
