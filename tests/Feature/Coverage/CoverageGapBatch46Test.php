<?php

declare(strict_types=1);

use App\Filament\Resources\PurchaseInbounds\Pages\ViewPurchaseInbound;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseInboundLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

function coverageInboundContext(User $actor): array
{
    $order = PurchaseOrder::factory()->accepted($actor)->create([
        'sent_at' => now(),
        'supplier_confirmation_required' => false,
    ]);

    $line = PurchaseOrderLine::factory()->create([
        'purchase_order_id' => $order->getKey(),
        'quantity_ordered' => '2.000000',
    ]);
    $line->forceFill([
        'transaction_quantity' => '2.000000',
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '2.000000',
        'received_base_quantity' => '0.000000',
    ])->save();

    $inbound = PurchaseInbound::factory()->create([
        'purchase_order_id' => $order->getKey(),
    ]);
    $inboundLine = PurchaseInboundLine::factory()->create([
        'purchase_inbound_id' => $inbound->getKey(),
        'purchase_order_line_id' => $line->getKey(),
    ]);
    $warehouse = Warehouse::factory()->create(['is_active' => true]);

    return [$order, $line, $inbound, $inboundLine, $warehouse];
}

it('executes purchase inbound allocation action and builds receivable options', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    [, , $inbound, $inboundLine, $warehouse] = coverageInboundContext($actor);

    $page = new ReflectionClass(ViewPurchaseInbound::class)->newInstanceWithoutConstructor();
    $actions = collect($page->getHeaderActions())->keyBy(static fn ($action): string => $action->getName());
    $allocate = $actions->get('allocateInbound');

    ($allocate->getActionFunction())($inbound, [
        'purchase_inbound_line_id' => $inboundLine->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'allocated_base_quantity' => '1.500000',
    ]);

    $allocation = PurchaseInboundAllocation::query()
        ->where('purchase_inbound_line_id', $inboundLine->getKey())
        ->where('warehouse_id', $warehouse->getKey())
        ->sole();

    expect((string) $allocation->allocated_base_quantity)->toBe('1.500000');

    $options = (new ReflectionMethod(ViewPurchaseInbound::class, 'receivableAllocationOptions'))
        ->invoke(null, $inbound->refresh());

    expect($options)->toHaveKey($allocation->getKey())
        ->and($options[$allocation->getKey()])->toContain($warehouse->name)
        ->toContain($inboundLine->purchaseOrderLine->productVariant->sku);
});

it('executes create or open receipt action for an inbound allocation', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    [, , $inbound, $inboundLine, $warehouse] = coverageInboundContext($actor);

    $allocation = app(\App\Services\Purchasing\PurchaseInboundService::class)->allocate(
        $actor,
        $inboundLine,
        $warehouse,
        '1.000000',
    );

    $page = new ReflectionClass(ViewPurchaseInbound::class)->newInstanceWithoutConstructor();
    $actions = collect($page->getHeaderActions())->keyBy(static fn ($action): string => $action->getName());
    $receipt = $actions->get('createOrOpenReceipt');

    ($receipt->getActionFunction())($inbound->refresh(), [
        'allocation_id' => $allocation->getKey(),
    ]);

    expect($allocation->refresh()->remainingBaseQuantity())->toBe('1.000000')
        ->and(\App\Models\InventoryOperation::query()->count())->toBe(1);
});

it('covers purchase inbound input guards and actor guard', function (): void {
    $integer = new ReflectionMethod(ViewPurchaseInbound::class, 'integerInput');
    $quantity = new ReflectionMethod(ViewPurchaseInbound::class, 'quantityInput');
    $positive = new ReflectionMethod(ViewPurchaseInbound::class, 'isPositiveQuantity');
    $actor = new ReflectionMethod(ViewPurchaseInbound::class, 'actor');

    expect($integer->invoke(null, '42'))->toBe(42)
        ->and($quantity->invoke(null, '1.250000'))->toBe('1.250000')
        ->and($positive->invoke(null, '0.100000'))->toBeTrue()
        ->and($positive->invoke(null, '0.000001'))->toBeFalse()
        ->and($positive->invoke(null, 'not-a-number'))->toBeFalse();

    expect(fn (): mixed => $integer->invoke(null, '1.5'))
        ->toThrow(LogicException::class, 'An integer workflow identifier is required.')
        ->and(fn (): mixed => $quantity->invoke(null, []))
        ->toThrow(LogicException::class, 'A numeric inbound quantity is required.');

    auth()->logout();

    expect(fn (): mixed => $actor->invoke(null))
        ->toThrow(LogicException::class, 'An authenticated Inventory user is required.');
});
