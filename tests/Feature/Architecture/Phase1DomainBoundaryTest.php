<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

it('keeps procurement monetary ownership out of the Inventory service namespace', function (): void {
    $root = base_path('app/Services/Inventory');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    $forbidden = [
        'SupplierProductReference',
        'purchase_cost',
        'last_received_unit_cost',
        'supplier_price',
        'procurement_price',
    ];
    $checked = 0;

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        expect($source)->toBeString();

        foreach ($forbidden as $token) {
            expect($source)->not->toContain($token);
        }

        $checked++;
    }

    expect($checked)->toBeGreaterThan(0);
});

it('uses dedicated phase one permissions for replenishment policy and inbound allocation', function (): void {
    expect(InventoryPermission::ReplenishmentPolicyView->value)->toBe('inventory.replenishment_policy.view')
        ->and(InventoryPermission::ReplenishmentPolicyManage->value)->toBe('inventory.replenishment_policy.manage')
        ->and(InventoryPermission::InboundAllocate->value)->toBe('inventory.inbound.allocate');

    $policy = file_get_contents(base_path('app/Policies/WarehouseReplenishmentPolicyPolicy.php'));
    $allocationUi = file_get_contents(base_path('app/Filament/Resources/PurchaseOrders/RelationManagers/AllocationsRelationManager.php'));
    $allocationService = file_get_contents(base_path('app/Services/Purchasing/PurchaseInboundService.php'));

    expect($policy)->toBeString()
        ->toContain('InventoryPermission::ReplenishmentPolicyView')
        ->toContain('InventoryPermission::ReplenishmentPolicyManage')
        ->and($allocationUi)->toBeString()
        ->toContain('InventoryPermission::InboundAllocate')
        ->and($allocationService)->toBeString()
        ->toContain('InventoryPermission::InboundAllocate');
});

it('does not introduce an Inventory Manager role as a second authorization path', function (): void {
    $roleEnum = file_get_contents(base_path('app/Enums/DashboardRole.php'));

    expect($roleEnum)->toBeString()
        ->not->toContain('InventoryManager');
});
