<?php

declare(strict_types=1);

use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SupplierProductReference;
use App\Services\Purchasing\SupplierCostWritebackService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('skips non-positive conversion factors and creates references from valid line snapshots', function (): void {
    $order = PurchaseOrder::factory()->create(['currency_code' => 'AED']);
    $skippedVariant = ProductVariant::factory()->create();
    $writtenVariant = ProductVariant::factory()->create();

    PurchaseOrderLine::factory()
        ->for($order, 'purchaseOrder')
        ->for($skippedVariant, 'productVariant')
        ->create([
            'conversion_factor_snapshot' => '0.000000',
            'unit_cost' => '50.00',
        ]);

    PurchaseOrderLine::factory()
        ->for($order, 'purchaseOrder')
        ->for($writtenVariant, 'productVariant')
        ->create([
            'conversion_factor_snapshot' => '2.000000',
            'unit_cost' => '50.00',
            'supplier_item_number' => 'SUP-SNAPSHOT-1',
        ]);

    app(SupplierCostWritebackService::class)->apply($order->refresh());

    expect(SupplierProductReference::query()
        ->where('supplier_id', $order->supplier_id)
        ->where('product_variant_id', $skippedVariant->getKey())
        ->exists())->toBeFalse();

    $reference = SupplierProductReference::query()
        ->where('supplier_id', $order->supplier_id)
        ->where('product_variant_id', $writtenVariant->getKey())
        ->sole();

    expect($reference->supplier_item_number)->toBe('SUP-SNAPSHOT-1')
        ->and((float) $reference->purchase_cost)->toBe(25.0)
        ->and($reference->currency_code)->toBe('AED');
});
