<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\OperationType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierConfirmationStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseInboundLine;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierConfirmation;
use App\Models\SupplierConfirmationItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Purchasing\PurchaseOrderSupplierCommitmentService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new ChartOfAccountsSeeder)->run();
    (new PurchasePermissionSeeder)->run();
    (new InventoryPermissionSeeder)->run();

    $this->manager = User::factory()->create();
    $this->manager->assignRole(DashboardRole::PurchasingManager->value);
    $this->manager->assignRole(DashboardRole::WarehouseManager->value);
    $this->actingAs($this->manager);
});

function queriesRunBy(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('decides supplier deletion from preloaded reference flags without per-row queries', function (): void {
    $free = Supplier::factory()->create();
    $referenced = Supplier::factory()->create();
    PurchaseOrder::factory()->for($referenced)->create();

    $suppliers = Supplier::query()->withExists(Supplier::referenceFlagRelations())->get()->keyBy('id');
    $results = [];
    $this->manager->can('delete', $suppliers[$free->id]); // warm the permission cache

    $queries = queriesRunBy(function () use ($suppliers, $free, $referenced, &$results): void {
        $results = [
            'free' => $this->manager->can('delete', $suppliers[$free->id]),
            'referenced' => $this->manager->can('delete', $suppliers[$referenced->id]),
        ];
    });

    expect($results)->toBe(['free' => true, 'referenced' => false])
        ->and($queries)->toBe(0)
        ->and($this->manager->can('delete', Supplier::query()->findOrFail($referenced->id)))->toBeFalse()
        ->and($this->manager->can('delete', Supplier::query()->findOrFail($free->id)))->toBeTrue();
});

it('treats only receipt operations as pinning a supplier in the preloaded flag', function (): void {
    $supplier = Supplier::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $supplier->inventoryOperations()->create([
        'operation_type' => OperationType::Receipt,
        'destination_warehouse_id' => $warehouse->id,
    ]);

    $flagged = Supplier::query()->withExists(Supplier::referenceFlagRelations())->findOrFail($supplier->id);

    expect($flagged->getAttribute(Supplier::RECEIPT_OPERATIONS_EXISTS))->toBeTrue()
        ->and($this->manager->can('delete', $flagged))->toBeFalse()
        ->and($this->manager->can('delete', Supplier::query()->findOrFail($supplier->id)))->toBeFalse();
});

it('decides product deletion from the loaded variants count and still falls back to a query', function (): void {
    $withVariant = Product::factory()->create();
    ProductVariant::factory()->create(['product_id' => $withVariant->id]);
    $without = Product::factory()->create();

    $counted = Product::query()->withCount('variants')->get()->keyBy('id');
    $this->manager->can('delete', $counted[$without->id]); // warm the permission cache

    $queries = queriesRunBy(function () use ($counted, $withVariant, $without): void {
        expect($this->manager->can('delete', $counted[$withVariant->id]))->toBeFalse()
            ->and($this->manager->can('delete', $counted[$without->id]))->toBeTrue();
    });

    expect($queries)->toBe(0)
        ->and($this->manager->can('delete', Product::query()->findOrFail($withVariant->id)))->toBeFalse()
        ->and($this->manager->can('delete', Product::query()->findOrFail($without->id)))->toBeTrue();
});

it('computes supplier-backed quantities identically from loaded relations with no queries', function (): void {
    $supplier = Supplier::factory()->create(['requires_confirmation' => true]);
    $order = PurchaseOrder::factory()->for($supplier)->create([
        'status' => PurchaseOrderStatus::Accepted,
        'sent_at' => now(),
        'supplier_confirmation_required' => true,
    ]);
    $variant = ProductVariant::factory()->create();
    $line = $order->lines()->create([
        'product_variant_id' => $variant->id,
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => 10,
        'unit_cost' => '5.00',
    ]);
    $line->forceFill([
        'transaction_quantity' => '10.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '10.000000',
        'received_base_quantity' => '0.000000',
        'line_total' => '50.00',
    ])->save();

    $confirmation = SupplierConfirmation::factory()->create(['purchase_order_id' => $order->id, 'supplier_id' => $supplier->id]);
    SupplierConfirmationItem::factory()->create([
        'supplier_confirmation_id' => $confirmation->id,
        'purchase_order_line_id' => $line->id,
        'product_variant_id' => $variant->id,
        'confirmation_status' => SupplierConfirmationStatus::Confirmed,
        'requested_base_quantity' => '10.000000',
        'confirmed_base_quantity' => '6.000000',
    ]);

    $inbound = PurchaseInbound::factory()->create(['purchase_order_id' => $order->id]);
    $inboundLine = PurchaseInboundLine::factory()->create([
        'purchase_inbound_id' => $inbound->id,
        'purchase_order_line_id' => $line->id,
    ]);
    PurchaseInboundAllocation::factory()->create([
        'purchase_inbound_line_id' => $inboundLine->id,
        'allocated_base_quantity' => '2.500000',
    ]);

    $service = app(PurchaseOrderSupplierCommitmentService::class);
    $fresh = $service->quantities($line->fresh());

    $loaded = PurchaseOrder::query()
        ->with(['supplier', 'lines.purchaseInboundLine.allocations', 'confirmations.items'])
        ->findOrFail($order->id);
    $loadedLine = $loaded->lines->firstOrFail();
    $fromMemory = [];

    $queries = queriesRunBy(function () use ($service, $loadedLine, $loaded, &$fromMemory): void {
        $fromMemory = $service->quantities($loadedLine, $loaded);
    });

    expect($fresh['confirmed'])->toBe('6.000000')
        ->and($fresh['allocated'])->toBe('2.500000')
        ->and($fromMemory)->toBe($fresh)
        ->and($queries)->toBe(0);
});
