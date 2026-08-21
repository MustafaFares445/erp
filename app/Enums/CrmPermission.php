<?php

declare(strict_types=1);

namespace App\Enums;

enum CrmPermission: string
{
    case CustomerView = 'crm.customer.view';
    case CustomerManage = 'crm.customer.manage';
    case CustomerRestore = 'crm.customer.restore';
    case PricingTierView = 'crm.pricing-tier.view';
    case PricingTierManage = 'crm.pricing-tier.manage';
    case PricingTierDiscountManage = 'crm.pricing-tier.discount.manage';
    case PricingTierLinkManage = 'crm.pricing-tier.link.manage';
    case PricingTierRestore = 'crm.pricing-tier.restore';
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
