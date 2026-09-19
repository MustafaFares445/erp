<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\RelationManagers\AllocationsRelationManager;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseInboundLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Purchasing\PurchaseInboundService;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function coverageInboundLine(string $quantity = '10.000000'): array
{
    $order = PurchaseOrder::factory()->accepted()->create();
    $purchaseOrderLine = PurchaseOrderLine::factory()->for($order)->create(['quantity_ordered' => $quantity,
        'transaction_quantity' => $quantity,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => $quantity,
        'received_base_quantity' => '0.000000',
    ]);
    $inbound = PurchaseInbound::factory()->for($order)->create();
    $line = PurchaseInboundLine::factory()->create([
        'purchase_inbound_id' => $inbound->getKey(),
        'purchase_order_line_id' => $purchaseOrderLine->getKey(),
    ]);

    return [$order, $purchaseOrderLine, $inbound, $line];
}

function allocationCoverageMethod(string $name): ReflectionMethod
{
    return new ReflectionMethod(AllocationsRelationManager::class, $name);
}

it('creates edits and removes inbound allocations through the relation manager actions', function (): void {
    (new InventoryPermissionSeeder)->run();
    [$order, , , $line] = coverageInboundLine();
    $actor = User::factory()->create();
    $actor->givePermissionTo(InventoryPermission::InboundAllocate->value);

    $warehouseA = Warehouse::factory()->create(['is_active' => true, 'name' => 'Coverage A']);
    $warehouseB = Warehouse::factory()->create(['is_active' => true, 'name' => 'Coverage B']);
    $inactive = Warehouse::factory()->create(['is_active' => false, 'name' => 'Coverage Inactive']);

    $this->actingAs($actor);
    expect(allocationCoverageMethod('canAllocate')->invoke(null))->toBeTrue()
        ->and(allocationCoverageMethod('requireActor')->invoke(null)->is($actor))->toBeTrue();

    $options = allocationCoverageMethod('availableWarehouseOptions')->invoke(null, $line);
    expect($options)->toHaveKeys([$warehouseA->id, $warehouseB->id])
        ->not->toHaveKey($inactive->id);

    Livewire::actingAs($actor)
        ->test(AllocationsRelationManager::class, [
            'ownerRecord' => $order,
            'pageClass' => ViewPurchaseOrder::class,
        ])
        ->callTableAction('addAllocation', $line, [
            'warehouse_id' => $warehouseA->id,
            'allocated_base_quantity' => '4.000000',
        ]);

    $allocation = PurchaseInboundAllocation::query()->sole();
    expect($allocation->warehouse_id)->toBe($warehouseA->id)
        ->and($allocation->allocated_base_quantity)->toBe('4.000000');
    Livewire::actingAs($actor)
        ->test(AllocationsRelationManager::class, [
            'ownerRecord' => $order,
            'pageClass' => ViewPurchaseOrder::class,
        ])
        ->callTableAction('editAllocation', $line, [
            'allocation_id' => $allocation->id,
            'warehouse_id' => $warehouseB->id,
            'allocated_base_quantity' => '5.000000',
        ]);

    expect($allocation->fresh()->warehouse_id)->toBe($warehouseB->id)
        ->and($allocation->fresh()->allocated_base_quantity)->toBe('5.000000');

    Livewire::actingAs($actor)
        ->test(AllocationsRelationManager::class, [
            'ownerRecord' => $order,
            'pageClass' => ViewPurchaseOrder::class,
        ])
        ->callTableAction('removeAllocation', $line, [
            'allocation_id' => $allocation->id,
        ]);

    expect(PurchaseInboundAllocation::query()->count())->toBe(0);
});

it('covers allocation helper totals and empty authentication branch', function (): void {
    (new InventoryPermissionSeeder)->run();
    [, $purchaseOrderLine, , $line] = coverageInboundLine();
    $actor = User::factory()->create();
    $actor->givePermissionTo(InventoryPermission::InboundAllocate->value);

    $warehouse = Warehouse::factory()->create(['is_active' => true]);
    $allocation = app(PurchaseInboundService::class)->allocate($actor, $line, $warehouse, '3.000000');

    $this->actingAs($actor);
    expect(allocationCoverageMethod('currentlyAllocatable')->invoke(null, $line->fresh()))->toBe('7.000000')
        ->and(allocationCoverageMethod('hasUnallocatedQuantity')->invoke(null, $line->fresh()))->toBeTrue()
        ->and(allocationCoverageMethod('singleAllocationId')->invoke(null, $line->fresh()))->toBe($allocation->id)
        ->and(allocationCoverageMethod('singleAllocation')->invoke(null, $line->fresh())->is($allocation))->toBeTrue()
        ->and(allocationCoverageMethod('receivedForLine')->invoke(null, $line->fresh()))->toBe('0.000000')
        ->and(allocationCoverageMethod('remainingForLine')->invoke(null, $line->fresh()))->toBe('10.000000');

    $purchaseOrderLine->forceFill(['received_base_quantity' => '3.500000'])->save();
    expect(allocationCoverageMethod('receivedForLine')->invoke(null, $line->fresh()))->toBe('3.500000')
        ->and(allocationCoverageMethod('remainingForLine')->invoke(null, $line->fresh()))->toBe('6.500000');

    auth()->logout();
    expect(fn (): mixed => allocationCoverageMethod('requireActor')->invoke(null))
        ->toThrow(LogicException::class, 'authenticated actor');
});
