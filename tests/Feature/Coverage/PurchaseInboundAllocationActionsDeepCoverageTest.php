<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Resources\PurchaseInbounds\Actions\PurchaseInboundAllocationActions;
use App\Models\InventoryOperation;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseInboundLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
    Gate::before(static fn (): bool => true);
});

function inboundActionActor(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo(InventoryPermission::InboundAllocate->value);

    return $actor;
}

/** @return array{PurchaseOrder, PurchaseOrderLine, PurchaseInbound, PurchaseInboundLine} */
function inboundActionFixture(string $quantity = '10.000000'): array
{
    $order = PurchaseOrder::factory()->accepted()->create();
    $orderLine = PurchaseOrderLine::factory()->for($order)->create([
        'quantity_ordered' => $quantity,
        'transaction_quantity' => $quantity,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => $quantity,
        'received_base_quantity' => '0.000000',
    ]);
    $variant = $orderLine->productVariant()->firstOrFail();
    $orderLine->forceFill([
        'unit_id' => $variant->unit_id,
        'transaction_unit_id' => $variant->unit_id,
    ])->save();

    $inbound = PurchaseInbound::factory()->for($order)->create();
    $line = PurchaseInboundLine::factory()->create([
        'purchase_inbound_id' => $inbound->getKey(),
        'purchase_order_line_id' => $orderLine->getKey(),
    ]);

    return [$order, $orderLine, $inbound, $line];
}

function inboundActionMethod(string $name): ReflectionMethod
{
    return new ReflectionMethod(PurchaseInboundAllocationActions::class, $name);
}

it('executes add edit remove and confirm allocation action closures', function (): void {
    [, , , $line] = inboundActionFixture();
    $actor = inboundActionActor();
    $warehouseA = Warehouse::factory()->create(['is_active' => true, 'name' => 'Inbound Action A']);
    $warehouseB = Warehouse::factory()->create(['is_active' => true, 'name' => 'Inbound Action B']);
    $this->actingAs($actor);

    $add = PurchaseInboundAllocationActions::add();
    $add->getActionFunction()($line, [
        'warehouse_id' => $warehouseA->getKey(),
        'allocated_base_quantity' => '4.000000',
    ]);

    $allocation = PurchaseInboundAllocation::query()->sole();
    expect($allocation->warehouse_id)->toBe($warehouseA->getKey())
        ->and($allocation->allocated_base_quantity)->toBe('4.000000');

    $edit = PurchaseInboundAllocationActions::edit();
    $edit->getActionFunction()($line->refresh(), [
        'allocation_id' => $allocation->getKey(),
        'warehouse_id' => $warehouseB->getKey(),
        'allocated_base_quantity' => '5.000000',
    ]);

    expect($allocation->refresh()->warehouse_id)->toBe($warehouseB->getKey())
        ->and($allocation->allocated_base_quantity)->toBe('5.000000');

    $remove = PurchaseInboundAllocationActions::remove();
    $remove->getActionFunction()($line->refresh(), [
        'allocation_id' => $allocation->getKey(),
    ]);

    expect(PurchaseInboundAllocation::query()->count())->toBe(0);

    $add->getActionFunction()($line->refresh(), [
        'warehouse_id' => $warehouseA->getKey(),
        'allocated_base_quantity' => '10.000000',
    ]);
    $confirmedAllocation = PurchaseInboundAllocation::query()->sole();

    $confirm = PurchaseInboundAllocationActions::confirm();
    $confirm->getActionFunction()($line->refresh(), [
        'allocation_id' => $confirmedAllocation->getKey(),
    ]);

    expect(InventoryOperation::query()
        ->whereHas('lines', fn ($query) => $query->where(
            'purchase_inbound_allocation_id',
            $confirmedAllocation->getKey(),
        ))
        ->exists())->toBeTrue();
});

it('covers allocation action visibility options and input helpers', function (): void {
    [, , , $line] = inboundActionFixture();
    $actor = inboundActionActor();
    $used = Warehouse::factory()->create(['is_active' => true, 'name' => 'Used Warehouse']);
    $available = Warehouse::factory()->create(['is_active' => true, 'name' => 'Available Warehouse']);
    $inactive = Warehouse::factory()->create(['is_active' => false, 'name' => 'Inactive Warehouse']);

    PurchaseInboundAllocation::factory()->create([
        'purchase_inbound_line_id' => $line->getKey(),
        'warehouse_id' => $used->getKey(),
        'allocated_base_quantity' => '2.000000',
    ]);

    $this->actingAs($actor);

    expect(inboundActionMethod('canAllocate')->invoke(null))->toBeTrue()
        ->and(inboundActionMethod('actor')->invoke(null)->is($actor))->toBeTrue();

    $availableOptions = inboundActionMethod('availableWarehouses')->invoke(null, $line);
    $activeOptions = inboundActionMethod('activeWarehouses')->invoke(null);
    $allocationOptions = inboundActionMethod('allocationOptions')->invoke(null, $line);

    expect($availableOptions)->toHaveKey($available->getKey())
        ->not->toHaveKey($used->getKey())
        ->not->toHaveKey($inactive->getKey())
        ->and($activeOptions)->toHaveKeys([$used->getKey(), $available->getKey()])
        ->not->toHaveKey($inactive->getKey())
        ->and($allocationOptions)->toHaveCount(1)
        ->and(inboundActionMethod('integerInput')->invoke(null, '42'))->toBe(42)
        ->and(inboundActionMethod('decimalInput')->invoke(null, 2.5))->toBe('2.5')
        ->and(inboundActionMethod('stringInput')->invoke(null, 12.5))->toBe('12.5')
        ->and(fn (): mixed => inboundActionMethod('integerInput')->invoke(null, 'x'))
        ->toThrow(LogicException::class, 'numeric allocation identifier')
        ->and(fn (): mixed => inboundActionMethod('decimalInput')->invoke(null, []))
        ->toThrow(LogicException::class, 'numeric allocation quantity')
        ->and(fn (): mixed => inboundActionMethod('stringInput')->invoke(null, []))
        ->toThrow(LogicException::class, 'scalar warehouse label');

    auth()->logout();

    expect(inboundActionMethod('canAllocate')->invoke(null))->toBeFalse()
        ->and(fn (): mixed => inboundActionMethod('actor')->invoke(null))
        ->toThrow(LogicException::class, 'authenticated warehouse actor');
});
