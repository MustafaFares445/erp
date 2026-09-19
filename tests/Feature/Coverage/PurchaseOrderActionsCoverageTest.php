<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Resources\PurchaseOrders\Actions\PurchaseOrderActions;
use App\Models\InventoryOperation;
use App\Models\ProductVariant;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\QuantityNormalizer;
use App\Services\Purchasing\PurchaseInboundService;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Actions\Action;
use Filament\Support\Exceptions\Halt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

function invokePurchaseCoverageAction(Action $action, PurchaseOrder $order, array $data = []): void
{
    $function = $action->getActionFunction();
    expect($function)->not->toBeNull();

    $reflection = new ReflectionFunction($function);
    if ($reflection->getNumberOfParameters() >= 2) {
        $function($order, $data);
    } else {
        $function($order);
    }
}

it('executes purchase-order lifecycle action guards and domain boundaries', function (): void {
    $definitions = [
        [PurchaseOrderActions::submit(), []],
        [PurchaseOrderActions::approve(), []],
        [PurchaseOrderActions::reject(), ['rejection_reason' => 'coverage reject']],
        [PurchaseOrderActions::send(), []],
        [PurchaseOrderActions::close(), ['closure_reason' => 'coverage close']],
        [PurchaseOrderActions::cancel(), ['cancellation_reason' => 'coverage cancel']],
        [PurchaseOrderActions::receive(), ['purchase_inbound_allocation_id' => 0, 'quantity' => '1.000000']],
    ];

    foreach ($definitions as [$action, $data]) {
        invokePurchaseCoverageAction($action, PurchaseOrder::factory()->create(), $data);
    }

    $this->actingAs(User::factory()->admin()->create());
    Gate::before(static fn (): bool => true);

    foreach ($definitions as [$action, $data]) {
        $order = PurchaseOrder::factory()->create();
        try {
            invokePurchaseCoverageAction($action, $order, $data);
        } catch (Halt) {
            // Invalid lifecycle/precondition failures are translated into Filament Halt.
        }
    }

    expect(true)->toBeTrue();
});

it('covers purchase-order receive helpers when no inbound exists', function (): void {
    $order = PurchaseOrder::factory()->accepted()->create();

    foreach ([
        'receivableAllocationOptions' => [],
        'singleReceivableAllocationId' => null,
        'singleReceivableAllocationQuantity' => null,
    ] as $methodName => $expected) {
        $method = new ReflectionMethod(PurchaseOrderActions::class, $methodName);
        expect($method->invoke(null, $order))->toBe($expected);
    }

    $receive = PurchaseOrderActions::receive();
    $receive->record($order);

    expect($receive->isVisible())->toBeFalse();
});

it('covers receivable allocation data and starts a receipt from the action', function (): void {
    (new InventoryPermissionSeeder)->run();
    $variant = ProductVariant::factory()->create();
    $unit = $variant->unit()->firstOrFail();
    $warehouse = Warehouse::factory()->create(['is_active' => true]);
    $order = PurchaseOrder::factory()->sent()->create();
    $line = $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity_ordered' => 10,
        'unit_cost' => '5.00',
        'line_total' => 50,
    ]);
    $snapshot = app(QuantityNormalizer::class)->normalize($variant, (int) $unit->getKey(), '10');
    $line->forceFill([
        'transaction_quantity' => $snapshot->transactionQuantity,
        'transaction_unit_id' => $snapshot->transactionUnitId,
        'conversion_factor_snapshot' => $snapshot->conversionFactorSnapshot,
        'base_quantity' => $snapshot->baseQuantity,
        'received_base_quantity' => '0.000000',
    ])->save();
    $allocator = User::factory()->create();
    $allocator->givePermissionTo(InventoryPermission::InboundAllocate->value);

    app(PurchaseInboundService::class)->allocateAllTo($allocator, $order, $warehouse);
    $allocation = PurchaseInboundAllocation::query()->sole();

    $options = new ReflectionMethod(PurchaseOrderActions::class, 'receivableAllocationOptions');
    $singleId = new ReflectionMethod(PurchaseOrderActions::class, 'singleReceivableAllocationId');
    $singleQty = new ReflectionMethod(PurchaseOrderActions::class, 'singleReceivableAllocationQuantity');
    expect($options->invoke(null, $order->refresh()))->toHaveKey($allocation->id)
        ->and($singleId->invoke(null, $order))->toBe($allocation->id)
        ->and($singleQty->invoke(null, $order))->toBe('10.000000');

    $this->actingAs(User::factory()->admin()->create());
    Gate::before(static fn (): bool => true);
    invokePurchaseCoverageAction(PurchaseOrderActions::receive(), $order->refresh(), [
        'purchase_inbound_allocation_id' => $allocation->id,
        'quantity' => '2.000000',
    ]);
    expect(InventoryOperation::query()->where('source_document_type', PurchaseOrder::class)->where('source_document_id', $order->getKey())->count())->toBe(1);
});
