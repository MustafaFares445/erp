<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Exceptions\Domain\SupplierOwnershipConflict;
use App\Models\Bill;
use App\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

uses(RefreshDatabase::class);

it('keeps legacy phase zero ownership columns out of the canonical schema', function (): void {
    $purchaseOrderWarehouseColumns = array_values(array_filter(
        Schema::getColumnListing('purchase_orders'),
        static fn (string $column): bool => str_contains($column, 'warehouse'),
    ));

    expect($purchaseOrderWarehouseColumns)->toBe([])
        ->and(Schema::hasColumn('inventory_stocks', 'reorder_level'))->toBeFalse()
        ->and(Schema::hasColumn('inventory_operation_lines', 'unit_cost'))->toBeFalse();
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

        expect($source, $path)->toBeString()
            ->not->toContain('SupplierProductReference')
            ->not->toContain('purchase_cost')
            ->not->toContain("'unit_cost'");
    }
});

it('keeps every Inventory and Warehouse Filament surface free of procurement monetary fields', function (): void {
    $root = base_path('app/Filament/Resources');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    $forbidden = [
        "'unit_cost'",
        "'purchase_cost'",
        "'last_received_unit_cost'",
        "'supplier_price'",
        "'procurement_price'",
    ];
    $checked = 0;

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());

        if (preg_match('#/(?:Inventory|Warehouse)[^/]*/#', $path) !== 1) {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        expect($source, $path)->toBeString();

        foreach ($forbidden as $field) {
            expect($source, $path)->not->toContain($field);
        }

        $checked++;
    }

    expect($checked)->toBeGreaterThan(0);
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
