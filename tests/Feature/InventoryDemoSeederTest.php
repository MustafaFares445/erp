<?php

declare(strict_types=1);

use App\Enums\DeliveryDocument;
use App\Enums\ProductType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Models\Brand;
use App\Models\CustomerProfile;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Package;
use App\Models\PackageType;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use App\Models\ProductVariantAttributeValue;
use App\Models\SerializedInventoryUnit;
use App\Models\Shipment;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\Warehouse;
use Database\Seeders\DentalCatalogSeeder;
use Database\Seeders\InventoryDemoSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds an idempotent dental catalogue without demo inventory data', function (): void {
    $seeder = new DentalCatalogSeeder;

    $seeder->run();
    $seeder->run();

    expect(Brand::query()->whereIn('code', ['FORMLABS', 'DENTSPLY-SIRONA', 'IVOCLAR'])->count())->toBe(3)
        ->and(Product::query()->count())->toBe(8)
        ->and(ProductVariant::query()->count())->toBe(16)
        ->and(ProductAttribute::query()->count())->toBe(9)
        ->and(ProductAttributeValue::query()->count())->toBe(34)
        ->and(ProductVariantAttributeValue::query()->count())->toBeGreaterThan(50)
        ->and(ProductVariantAttributeValue::query()->whereHas('variant', fn (Builder $query): Builder => $query->where('sku', 'FORMLABS-FORM-4B'))->count())->toBe(6)
        ->and(ProductVariant::query()->where('sku', 'like', 'DEMO-%')->exists())->toBeFalse();
});

it('seeds connected purchasing and inventory workflow scenarios idempotently', function (): void {
    $seeder = new InventoryDemoSeeder;

    $seeder->run();
    $seeder->run();

    expect(Supplier::query()->whereIn('code', ['FORMLABS-US', 'DENTSPLY-MENA', 'IVOCLAR-LEVANT'])->count())->toBe(3)
        ->and(PackageType::query()->count())->toBe(5)
        ->and(Package::query()->count())->toBe(3)
        ->and(Package::query()->whereHas('operationLines')->count())->toBe(2)
        ->and(Product::query()->whereHas('media')->count())->toBe(7)
        ->and(Product::query()->where('name', 'Precision Model Resin')->firstOrFail()->getMedia('images'))->toHaveCount(2)
        ->and(SupplierProductReference::query()->count())->toBe(16)
        ->and(InventoryOperation::query()
            ->where('operation_type', 'receipt')
            ->whereIn('supplier_reference', [
                'FL-INV-2026-1001',
                'FL-INV-2026-1014',
                'FL-DEMO-COVERAGE-2026-2001',
                'DS-DEMO-COVERAGE-2026-2002',
                'IV-DEMO-COVERAGE-2026-2003',
                'FL-DEMO-COVERAGE-2026-2004',
                'FL-DEMO-COVERAGE-2026-2005',
            ])
            ->count())->toBe(7)
        ->and(InventoryOperation::query()->where('notes', 'Demo workflow: delivered Formlabs replenishment.')->where('stage', 'done')->count())->toBe(1)
        ->and(InventoryOperation::query()->where('notes', 'Demo workflow: reserved resin for Smile Dental Clinic.')->where('stage', 'ready')->count())->toBe(1)
        ->and(InventoryOperation::query()->where('notes', 'Demo workflow: cold-chain stock transfer awaiting receipt.')->where('stage', 'in_transit')->count())->toBe(1)
        ->and(InventoryOperation::query()->where('notes', 'Demo workflow: draft Dentsply purchase order pending approval.')->where('stage', 'draft')->count())->toBe(1)
        ->and(InventoryOperation::query()->where('notes', 'Demo workflow: waiting for unavailable Primeprint PPU stock.')->where('stage', 'waiting')->count())->toBe(1);

    $smile = CustomerProfile::query()->where('customer_code', 'DEMO-SMILE')->sole();
    $order = Order::query()->where('order_number', 'SO-2026-0001')->with('lines')->sole();
    $delivery = InventoryOperation::query()->where('notes', 'Demo workflow: reserved resin for Smile Dental Clinic.')->sole();
    $deliveries = InventoryOperation::query()->where('operation_type', 'delivery')->get();

    expect(CustomerProfile::query()->where('country', 'AE')->count())->toBe(2)
        ->and(CustomerProfile::query()->whereBetween('latitude', [22.5, 26.5])->whereBetween('longitude', [51.5, 56.5])->count())->toBe(2)
        ->and(Warehouse::query()->whereBetween('latitude', [22.5, 26.5])->whereBetween('longitude', [51.5, 56.5])->count())->toBe(3)
        ->and($order->customer_id)->toBe($smile->getKey())
        ->and($order->lines)->toHaveCount(1)
        ->and($delivery->customer_id)->toBe($smile->getKey())
        ->and($delivery->source_document_type)->toBe(Order::class)
        ->and($delivery->source_document_id)->toBe($order->getKey())
        ->and($delivery->sourceDocument()->firstOrFail()->is($order))->toBeTrue()
        ->and($deliveries)->not->toBeEmpty();

    foreach ($deliveries as $seededDelivery) {
        expect(array_filter(array_map(static fn (DeliveryDocument $document): mixed => $seededDelivery->getFirstMedia($document->value), DeliveryDocument::cases())))
            ->toHaveCount(count(DeliveryDocument::cases()));
    }

    expect(InventoryOperation::query()->where('operation_type', 'delivery')->whereNull('customer_id')->count())->toBe(0)
        ->and(InventoryOperation::query()->where('operation_type', 'delivery')->whereNull('delivery_type')->count())->toBe(0)
        ->and(Shipment::query()->whereHas('order', fn (Builder $orders): Builder => $orders->where('order_number', 'SO-2026-0001'))->count())->toBe(1)
        ->and(Shipment::query()->whereHas('media')->count())->toBe(1)
        ->and(array_filter(array_map(static fn (DeliveryDocument $document): mixed => $delivery->getFirstMedia($document->value), DeliveryDocument::cases())))->toHaveCount(count(DeliveryDocument::cases()));
});

it('stocks every catalogue variant with the tracking data its product type requires', function (): void {
    (new InventoryDemoSeeder)->run();

    expect(ProductVariant::query()->whereDoesntHave('stocks')->pluck('sku')->all())->toBe([])
        ->and((float) InventoryStock::query()->whereHas('productVariant.product', fn (Builder $products): Builder => $products->where('product_type', ProductType::Grain->value))->sum('on_hand_quantity'))->toBe(50.0);

    $machineStocks = InventoryStock::query()
        ->whereHas('productVariant.product', fn (Builder $products): Builder => $products->where('product_type', ProductType::Machine->value))
        ->get();

    foreach ($machineStocks as $stock) {
        expect(SerializedInventoryUnit::query()
            ->where('product_variant_id', $stock->product_variant_id)
            ->where('warehouse_id', $stock->warehouse_id)
            ->where('status', SerializedInventoryUnitStatus::Available)
            ->count())->toBe((int) $stock->on_hand_quantity);
    }

    $expiryVariants = ProductVariant::query()
        ->whereHas('product', fn (Builder $products): Builder => $products->where('product_type', ProductType::ExpiryMaterial->value))
        ->get();

    foreach ($expiryVariants as $variant) {
        expect(InventoryLot::query()->where('product_variant_id', $variant->getKey())->whereDate('expires_at', '>', today())->exists())->toBeTrue();
    }
});

it('does not let an unrelated canonical main-warehouse receipt suppress the demo scenarios', function (): void {
    $mainWarehouse = Warehouse::factory()->create(['code' => 'MAIN']);

    InventoryOperation::factory()->receipt()->create([
        'destination_warehouse_id' => $mainWarehouse->getKey(),
        'supplier_reference' => 'CLIENT-RECEIPT-2026-0001',
    ]);

    (new InventoryDemoSeeder)->run();

    expect(InventoryOperation::query()
        ->where('operation_type', 'receipt')
        ->where('supplier_reference', 'FL-INV-2026-1001')
        ->exists())->toBeTrue()
        ->and(InventoryOperation::query()->where('notes', 'Demo workflow: delivered Formlabs replenishment.')->exists())->toBeTrue();
});
