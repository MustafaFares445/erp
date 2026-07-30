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
