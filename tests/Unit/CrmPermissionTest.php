<?php

declare(strict_types=1);

use App\Enums\CrmPermission;
use App\Enums\InventoryPermission;

it('has a unique typed CRM permission catalogue', function (): void {
    expect(CrmPermission::values())
        ->toHaveCount(count(CrmPermission::cases()))
        ->toHaveCount(count(array_unique(CrmPermission::values())))
        ->each->toStartWith('crm.');
});

it('keeps price-floor approval separate from CRM permissions', function (): void {
    expect(InventoryPermission::PriceFloorApprove->value)->toBe('inventory.price-floor.approve')
        ->and(CrmPermission::values())->not->toContain(InventoryPermission::PriceFloorApprove->value);
});

it('contains pricing tier permissions and no obsolete subscription permissions', function (): void {
    expect(CrmPermission::values())
        ->toContain(CrmPermission::PricingTierView->value, CrmPermission::PricingTierManage->value, CrmPermission::PricingTierLinkManage->value)
        ->and(implode('|', CrmPermission::values()))->not->toContain('subscription');
});

it('lists the fixed CRM role names used to seed the dashboard roles', function (): void {
    expect(CrmPermission::fixedRoleNames())
        ->toBe(['System Admin', 'CRM Manager', 'Pricing Manager', 'Reviewer']);
});
