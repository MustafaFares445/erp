<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\InventoryPermission;
use App\Enums\SupplierConfirmationStatus;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Purchasing\Exceptions\InvalidPurchaseInboundAllocation;
use App\Services\Purchasing\PurchaseInboundService;
use App\Services\Purchasing\PurchaseOrderSupplierCommitmentService;
use App\Services\Purchasing\SupplierConfirmationService;
use Carbon\CarbonImmutable;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
    (new PurchasePermissionSeeder)->run();
});

it('accumulates append-only supplier follow-up evidence without reopening historical allocation', function (): void {
    $supplier = Supplier::factory()->create(['requires_confirmation' => true]);
    $order = PurchaseOrder::factory()->sent()->for($supplier)->create();
    $variant = ProductVariant::factory()->create();
    /** @var Unit $unit */
    $unit = $variant->unit()->firstOrFail();
    /** @var PurchaseOrderLine $line */
    $line = $order->lines()->create([
        'product_variant_id' => $variant->id,
        'unit_id' => $unit->id,
        'quantity_ordered' => '100',
        'unit_cost' => '1.00',
        'line_total' => '100.00',
    ]);
    $line->forceFill([
        'transaction_quantity' => '10.000000',
        'transaction_unit_id' => $unit->id,
        'conversion_factor_snapshot' => '10.000000',
        'base_quantity' => '100.000000',
        'received_base_quantity' => '0.000000',
    ])->save();

    $purchasingOfficer = User::factory()->create();
    $purchasingOfficer->assignRole(DashboardRole::PurchasingOfficer->value);

    $allocator = User::factory()->create();
    $allocator->givePermissionTo(InventoryPermission::InboundAllocate->value);

    $confirmationService = app(SupplierConfirmationService::class);

    $firstConfirmation = $confirmationService->recordPurchaseOrder($purchasingOfficer, $order);
    $confirmationService->respond(
        $purchasingOfficer,
        $firstConfirmation,
        SupplierConfirmationStatus::Partial,
        CarbonImmutable::parse($order->ordered_at)->addWeek(),
        'Only part in stock',
        [[
            'id' => $firstConfirmation->items->sole()->id,
            'confirmed_base_quantity' => '70',
            'backordered_base_quantity' => '30',
        ]],
    );

    $commitments = app(PurchaseOrderSupplierCommitmentService::class);

    expect($commitments->quantities($line->fresh())['confirmed'])->toBe('70.000000')
        ->and($commitments->quantities($line->fresh())['backordered'])->toBe('30.000000');

    $inbound = app(PurchaseInboundService::class)->ensureForAccepted($order);
    $inboundLine = $inbound->lines()->sole();
    $firstWarehouse = Warehouse::factory()->create(['is_active' => true]);
    $secondWarehouse = Warehouse::factory()->create(['is_active' => true]);

    app(PurchaseInboundService::class)->allocate($allocator, $inboundLine, $firstWarehouse, '70');

    expect(fn () => app(PurchaseInboundService::class)->allocate($allocator, $inboundLine, $secondWarehouse, '1'))
        ->toThrow(InvalidPurchaseInboundAllocation::class);

    $followUp = $confirmationService->recordPurchaseOrder($purchasingOfficer, $order->fresh());

    expect($followUp->items->sole()->requested_base_quantity)->toBe('30.000000')
        ->and($followUp->items->sole()->requested_quantity)->toBe('3.000');

    $confirmationService->respond(
        $purchasingOfficer,
        $followUp,
        SupplierConfirmationStatus::Confirmed,
        CarbonImmutable::parse($order->ordered_at)->addWeek(),
        'Rest is in now',
        [[
            'id' => $followUp->items->sole()->id,
            'confirmed_base_quantity' => '30',
            'backordered_base_quantity' => '0',
        ]],
    );

    $quantities = $commitments->quantities($line->fresh());

    expect($quantities['confirmed'])->toBe('100.000000')
        ->and($quantities['backordered'])->toBe('0.000000')
        ->and($quantities['currently_allocatable'])->toBe('30.000000');

    app(PurchaseInboundService::class)->allocate($allocator, $inboundLine->fresh(), $secondWarehouse, '30');

    expect($inboundLine->fresh()->allocatedBaseQuantity())->toBe('100.000000');
});
