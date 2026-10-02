<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Payments\SystemActorResolver;

/**
 * Canonical list of every module's fixed dashboard role names.
 *
 * Single source of truth consulted by every `isAdmin()`-bypass authorization
 * check across modules (CRM, Inventory-adjacent pricing, and Employees), so
 * adding one module's fixed role automatically narrows every other module's
 * bypass instead of requiring each existing check to be edited again.
 *
 * Adding a case here narrows every other module's bypass, which is the point:
 * a user given a scoped role in any module is thereafter checked explicitly
 * everywhere. historical Spec Kit 018's `Chief Accountant` and `Accountant` are held to the
 * same rule, and `AccountingRoleNarrowingTest` proves the narrowing rather
 * than assuming it.
 *
 * historical Spec Kit 017's `Purchasing Manager` and `Purchasing Officer` are held to it too,
 * and `PurchasingRoleNarrowingTest` proves the narrowing rather than assuming
 * it — an admin who is also given a purchasing role loses bypass in Inventory,
 * CRM, Employees, Support, and Accounting as well, which is a real behavioural
 * change to shipped code and is tested as one.
 *
 * historical Spec Kit 019's `Sales Manager`, `Sales Officer`, and `Billing Officer` are held
 * to it too, proved by `SalesRoleNarrowingTest`: granting a System Admin any
 * one of the three removes their admin bypass in Inventory, CRM, Employees,
 * Support, Accounting, and Purchasing as well.
 *
 * The Customer App V1 `System Integration` role is held to the same rule for
 * a different reason: it is never assigned to a real admin, only to the
 * single seeded actor {@see SystemActorResolver}
 * resolves for automated provider/settlement flows — being a fixed role is
 * what confines that actor to its two explicitly granted abilities instead
 * of silently inheriting the blanket admin bypass.
 *
 * @see /Docs/domains/employees/README.md R-006
 * @see /Docs/product/ROLES_AND_PERMISSIONS.md §4
 * @see /Docs/product/ROLES_AND_PERMISSIONS.md §4
 * @see /Docs/product/ROLES_AND_PERMISSIONS.md §2
 */
enum DashboardRole: string
{
    case SystemAdmin = 'System Admin';
    case CrmManager = 'CRM Manager';
    case PricingManager = 'Pricing Manager';
    case Reviewer = 'Reviewer';
    case EmployeeManager = 'Employee Manager';
    case PayrollOfficer = 'Payroll Officer';
    case SupportManager = 'Support Manager';
    case SupportAgent = 'Support Agent';
    case ChiefAccountant = 'Chief Accountant';
    case Accountant = 'Accountant';
    case PurchasingManager = 'Purchasing Manager';
    case PurchasingOfficer = 'Purchasing Officer';
    case WarehouseManager = 'Warehouse Manager';
    case SalesManager = 'Sales Manager';
    case SalesOfficer = 'Sales Officer';
    case BillingOfficer = 'Billing Officer';
    case SystemIntegration = 'System Integration';

    /** @return list<string> */
    public static function fixedRoleNames(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }
}
