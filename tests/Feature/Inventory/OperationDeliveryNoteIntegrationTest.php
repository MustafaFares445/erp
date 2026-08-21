<?php

declare(strict_types=1);

use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * FR-012, FR-013, P-1: an operation may reference an originating commercial document, and a
 * confirmed delivery must move stock exactly once.
 *
 * Discovery (P-1 audit): this codebase has no `DeliveryNote` or `PurchaseOrder` model, service, or
 * Filament resource — `AdminModuleRegistry` links to `DeliveryNoteResource::class` etc. as
 * forward-looking placeholders that do not exist, resolved defensively to nothing. There is
 * therefore no existing stock-moving path for P-1 to audit away: the double-fire risk the
 * contract warns about does not apply to this codebase's current state. What this feature must
 * still deliver is the `source_document` polymorphic hook itself (already on
 * `inventory_operations` per data-model.md §2) and the guarantee that completing a Delivery
 * operation writes stock exactly once — both asserted below, using an existing unrelated model
 * (`Supplier`) as a stand-in commercial document, since the mechanism is generic over any
 * Eloquent model. A future Sales module attaches through this same hook without any change here.
 */
function deliveryIntegrationService(): InventoryOperationService
{
    return app(InventoryOperationService::class);
}

it('carries an arbitrary source document reference through to completion', function (): void {
    $source = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($source)->create(['on_hand_quantity' => '5.000', 'reserved_quantity' => 0, 'available_quantity' => '5.000']);
    // The factory's default variant is a Grain, which is batch-tracked, so the line below has
    // to name the batch it draws from.
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($source)->create(['on_hand_quantity' => '5.000', 'reserved_quantity' => '0.000', 'expires_at' => null]);
    $supplier = Supplier::factory()->create();

    $operation = InventoryOperation::factory()->delivery()->create([
        'source_warehouse_id' => $source->getKey(),
        'source_document_type' => Supplier::class,
        'source_document_id' => $supplier->getKey(),
    ]);
    $operation->lines()->create(['product_variant_id' => $variant->getKey(), 'quantity' => '1.000', 'unit_id' => $variant->unit_id, 'inventory_lot_id' => $lot->getKey()]);

    expect($operation->sourceDocument()->first()?->is($supplier))->toBeTrue();

    deliveryIntegrationService()->markReady($operation);
    deliveryIntegrationService()->complete($operation->refresh(), User::factory()->create());

    expect($operation->refresh()->sourceDocument()->first()?->is($supplier))->toBeTrue();
});

it('moves stock exactly once when a delivery operation is completed', function (): void {
    $source = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($source)->create([
        'on_hand_quantity' => '9.000',
        'reserved_quantity' => '0.000',
        'available_quantity' => '9.000',
    ]);
    // The factory's default variant is a Grain, which is batch-tracked, so the line below has
    // to name the batch it draws from.
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($source)->create(['on_hand_quantity' => '9.000', 'reserved_quantity' => '0.000', 'expires_at' => null]);
    $operation = InventoryOperation::factory()->delivery()->create(['source_warehouse_id' => $source->getKey()]);
    $operation->lines()->create(['product_variant_id' => $variant->getKey(), 'quantity' => '9.000', 'unit_id' => $variant->unit_id, 'inventory_lot_id' => $lot->getKey()]);

    deliveryIntegrationService()->markReady($operation);
    deliveryIntegrationService()->complete($operation->refresh(), User::factory()->create());

    $stock = InventoryStock::query()->where('product_variant_id', $variant->getKey())->where('warehouse_id', $source->getKey())->firstOrFail();

    expect((float) $stock->on_hand_quantity)->toBe(0.0)
        ->and(InventoryMovement::query()->where('source_type', 'inventory_operation')->where('source_id', $operation->getKey())->count())->toBe(1);
});
