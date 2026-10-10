<?php

declare(strict_types=1);

use App\Enums\InventoryDiagnosticSeverity;
use App\Enums\InventoryPermission;
use App\Enums\ShipmentStatus;
use App\Filament\Resources\OutboundFulfillments\Pages\ViewOutboundFulfillment;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Inventory\Diagnostics\OutboundFulfillmentDiagnoser;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('flags a ready delivery with an unlinked sales order line instead of treating its quantity as planned', function (): void {
    $order = Order::factory()->create();
    $variant = ProductVariant::factory()->create();
    OrderLine::factory()->create([
        'order_id' => $order->getKey(),
        'product_variant_id' => $variant->getKey(),
        'quantity' => 3,
        'unit_id' => $variant->unit_id,
    ]);
    $delivery = InventoryOperation::factory()->delivery()->ready()->create([
        'source_document_type' => $order->getMorphClass(),
        'source_document_id' => $order->getKey(),
    ]);
    InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $delivery->getKey(),
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity' => 3,
        'base_quantity' => 3,
        'order_line_id' => null,
    ]);
    Shipment::factory()->create([
        'order_id' => $order->getKey(),
        'inventory_operation_id' => $delivery->getKey(),
        'warehouse_id' => $delivery->source_warehouse_id,
        'status' => ShipmentStatus::Planned,
    ]);

    $diagnosis = app(OutboundFulfillmentDiagnoser::class)->diagnose($order);

    expect($diagnosis->find('outbound.unlinked_delivery')?->severity)
        ->toBe(InventoryDiagnosticSeverity::Warning)
        ->and($diagnosis->has('outbound.partially_planned'))->toBeTrue()
        ->and($diagnosis->find('outbound.unlinked_delivery')?->title)
        ->not->toContain('inventory_view_ux.');
});

it('renders concise workflow updates and inline term help on the actual outbound view page', function (): void {
    (new InventoryPermissionSeeder)->run();
    $user = User::factory()->admin()->create();
    $user->givePermissionTo(array_map(
        static fn (InventoryPermission $permission): string => $permission->value,
        InventoryPermission::cases(),
    ));
    $order = Order::factory()->create();
    $variant = ProductVariant::factory()->create();
    OrderLine::factory()->create([
        'order_id' => $order->getKey(),
        'product_variant_id' => $variant->getKey(),
        'quantity' => 2,
        'unit_id' => $variant->unit_id,
    ]);

    Livewire::actingAs($user)
        ->test(ViewOutboundFulfillment::class, ['record' => $order->getKey()])
        ->assertOk()
        ->assertSee('data-inventory-workflow-updates', escape: false)
        ->assertSee('data-term-help', escape: false)
        ->assertSee(trans_choice('inventory_view_explanations.workflow_details', 1, ['count' => 1]));
});
