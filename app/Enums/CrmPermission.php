<?php

declare(strict_types=1);

namespace App\Enums;

enum CrmPermission: string
{
    case CustomerView = 'crm.customer.view';
    case CustomerManage = 'crm.customer.manage';
    case CustomerRestore = 'crm.customer.restore';
    case SubscriptionView = 'crm.subscription.view';
    case SubscriptionManage = 'crm.subscription.manage';
    case SubscriptionDiscountManage = 'crm.subscription.discount.manage';
    case SubscriptionLinkManage = 'crm.subscription.link.manage';
    case SubscriptionRestore = 'crm.subscription.restore';
    case PricePreview = 'crm.price.preview';
    case ReportView = 'crm.report.view';
    case AuditView = 'crm.audit.view';
    case DashboardRoleAssign = 'crm.dashboard-role.assign';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $permission): string => $permission->value, self::cases());
    }

    /** @return list<string> */
    public static function fixedRoleNames(): array
    {
        return ['System Admin', 'CRM Manager', 'Pricing Manager', 'Reviewer'];
    }
}
