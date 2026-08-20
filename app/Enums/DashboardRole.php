<?php

declare(strict_types=1);

namespace App\Enums;

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
 * everywhere. Spec 018's `Chief Accountant` and `Accountant` are held to the
 * same rule, and `AccountingRoleNarrowingTest` proves the narrowing rather
 * than assuming it.
 *
 * @see /specs/015-employees-plans-visits-dashboard/research.md R-006
 * @see /specs/018-chart-of-accounts-journals/contracts/permissions.md §4
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

    /** @return list<string> */
    public static function fixedRoleNames(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }
}
