<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Exceptions\Domain\SupplierOwnershipConflict;
use App\Models\Bill;
use App\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('keeps legacy warehouse and reorder ownership columns out of the canonical schema', function (): void {
    expect(Schema::hasColumn('purchase_orders', 'destination_warehouse_id'))->toBeFalse()
        ->and(Schema::hasColumn('inventory_stocks', 'reorder_level'))->toBeFalse();
});

it('forces a purchase-order bill to derive its supplier instead of duplicating supplier ownership', function (): void {
    $order = PurchaseOrder::factory()->accepted()->create();

    expect(fn (): Bill => Bill::factory()->create([
        'purchase_order_id' => $order->getKey(),
        'supplier_id' => $order->supplier_id,
    ]))->toThrow(SupplierOwnershipConflict::class);
});

it('keeps physical receiving paths quantity-only and unable to write supplier commercial cost', function (): void {
    $paths = [
        'app/Services/Purchasing/PurchaseOrderReceivingService.php',
        'app/Services/Inventory/InventoryOperationService.php',
        'app/Listeners/AdvancePurchaseOrderOnOperationCompleted.php',
    ];

    foreach ($paths as $path) {
        $source = file_get_contents(base_path($path));

        expect($source)->toBeString()
            ->not->toContain('SupplierProductReference')
            ->not->toContain('purchase_cost')
            ->not->toContain("'unit_cost'");
    }
});

it('keeps inventory operation forms free of procurement price inputs', function (): void {
    $paths = [
        'app/Filament/Resources/InventoryOperations/Schemas/InventoryOperationForm.php',
        'app/Filament/Resources/InventoryOperations/Schemas/OperationLinesRepeater.php',
    ];

    foreach ($paths as $path) {
        $source = file_get_contents(base_path($path));

        expect($source)->toBeString()
            ->not->toContain("TextInput::make('unit_cost')")
            ->not->toContain("TextInput::make('purchase_cost')")
            ->not->toContain("TextInput::make('last_received_unit_cost')");
    }
});

it('keeps purchasing permissions out of the inventory permission catalogue', function (): void {
    foreach (InventoryPermission::cases() as $permission) {
        expect(str_starts_with($permission->value, 'purchase.'))->toBeFalse()
            ->and(str_starts_with($permission->value, 'purchasing.'))->toBeFalse();
    }
});

it('guards supplier commercial controls with purchasing permissions on the shared supplier resource', function (): void {
    $source = file_get_contents(base_path('app/Filament/Resources/Suppliers/SupplierResource.php'));

    expect($source)->toBeString()
        ->toContain('PurchasePermission::ProductReferenceManage')
        ->toContain('PurchasePermission::SupplierManage')
        ->toContain("Repeater::make('productReferences')")
        ->toContain("TextInput::make('purchase_cost')");
});
