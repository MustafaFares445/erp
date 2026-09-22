<?php

declare(strict_types=1);

namespace App\Enums;

use App\Policies\PurchaseSettingPolicy;

/**
 * Canonical `system.*` permission catalogue (guard: `web`).
 *
 * Separate from the seven module catalogues because business constraints are
 * not owned by any one module: a discount ceiling governs CRM pricing, Sales
 * quoting and Inventory catalogue maintenance at once.
 *
 * Both abilities are System Admin only, for the reason
 * {@see PurchaseSettingPolicy} already records about the
 * purchasing threshold: whoever can raise a limit can approve their own
 * breach by moving the line rather than by breaking a rule.
 */
enum SystemPermission: string
{
    case ConstraintView = 'system.constraint.view';
    case ConstraintManage = 'system.constraint.manage';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $permission): string => $permission->value, self::cases());
    }
}
