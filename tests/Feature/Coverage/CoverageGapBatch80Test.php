<?php

declare(strict_types=1);

use App\Data\Inventory\PriceFloorOverrideData;
use App\Enums\ProductStatus;
use App\Filament\Resources\Quotations\Support\QuotationLinePriceFloorApprovals;
use App\Filament\Resources\Suppliers\Schemas\SupplierInfolist;
use App\Models\CustomerPricingTier;
use App\Models\CustomerProfile;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\PriceFloorOverride;
use App\Models\PricingTier;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\SalesOpportunity;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Crm\CrmFunnelReportService;
use App\Services\Inventory\ProductPricingService;
use App\Services\Inventory\StockAvailabilityExplainer;
use App\Services\Sales\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

it('covers zero-net in-transit lines without surfacing a transfer', function (): void {
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();

    $transfer = InventoryOperation::factory()->internalTransfer()->inTransit()->create([
        'destination_warehouse_id' => $warehouse->getKey(),
    ]);

    InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $transfer->getKey(),
        'product_variant_id' => $variant->getKey(),
        'dispatched_base_quantity' => '2.000000',
        'received_base_quantity' => '2.000000',
    ]);

    $explanation = app(StockAvailabilityExplainer::class)->explain($variant, $warehouse);

    expect($explanation['in_transit']['quantity'])->toBe(0.0)
        ->and($explanation['in_transit']['operations'])->toBe([]);
});

it('records pricing-tier provenance when a matching tier price is approved below floor', function (): void {
    $actor = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create([
        'base_price' => 100,
        'min_price' => 90,
        'status' => ProductStatus::Active,
    ]);
    $variant->product->update(['status' => ProductStatus::Active]);

    $tier = PricingTier::factory()->productScoped()->create([
        'discount_value' => 20,
    ]);
    $tier->products()->attach($variant->product_id);
    CustomerPricingTier::factory()->create([
        'customer_user_id' => $customer->user_id,
        'pricing_tier_id' => $tier->getKey(),
        'is_active' => true,
    ]);

    $resolved = app(QuotationLinePriceFloorApprovals::class)->resolve([
        [
            'product_variant_id' => $variant->getKey(),
            'unit_price' => 80,
            'price_floor_override_reason' => 'Batch 80 tier-aligned floor approval',
        ],
    ], $customer->getKey(), $actor);

    $overrideId = $resolved[0]['price_floor_override_id'] ?? null;
    expect($overrideId)->toBeInt();

    $override = PriceFloorOverride::query()->findOrFail($overrideId);
    expect($override->pricing_tier_id)->toBe($tier->getKey())
        ->and((float) $override->attempted_price)->toBe(80.0);
});

it('passes a string price-floor override id through standalone invoice pricing provenance', function (): void {
    $actor = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create([
        'base_price' => 100,
        'min_price' => 90,
    ]);

    $override = app(ProductPricingService::class)->approveFloorOverride(
        new PriceFloorOverrideData(
            $variant->getKey(),
            $customer->user_id,
            85,
            'Batch 80 standalone invoice override',
        ),
        $actor,
    );

    $invoice = app(InvoiceService::class)->createStandalone(
        $actor,
        ['customer_id' => $customer->getKey()],
        [[
            'product_variant_id' => $variant->getKey(),
            'quantity' => 1,
            'unit_price' => 85,
            'tax_amount' => 0,
            'price_floor_override_id' => (string) $override->getKey(),
        ]],
    );

    expect($invoice->lines()->sole()->price_floor_override_id)->toBe($override->getKey());
});

it('covers CRM pipeline age when the opportunity has no created timestamp', function (): void {
    $opportunity = SalesOpportunity::factory()->create([
        'currency' => 'AED',
        'estimated_value_minor' => 12345,
    ]);

    DB::table('sales_opportunities')
        ->where('id', $opportunity->getKey())
        ->update(['created_at' => null]);

    $rows = app(CrmFunnelReportService::class)->pipelineAge();

    expect($rows)->not->toBeEmpty()
        ->and((float) $rows->first()['average_age_days'])->toBe(0.0);
});

it('covers supplier completed-receipt lead time and on-time metrics directly', function (): void {
    $supplier = Supplier::factory()->create();

    $order = PurchaseOrder::factory()->received()->create([
        'supplier_id' => $supplier->getKey(),
        'ordered_at' => '2026-09-01',
        'expected_at' => '2026-09-03',
    ]);

    InventoryOperation::factory()->receipt()->done()->create([
        'source_document_type' => PurchaseOrder::class,
        'source_document_id' => $order->getKey(),
        'completed_at' => '2026-09-02 12:00:00',
    ]);

    $lead = new ReflectionMethod(SupplierInfolist::class, 'averageReceiptLeadTime');
    $onTime = new ReflectionMethod(SupplierInfolist::class, 'onTimeReceiptSummary');

    expect($lead->invoke(null, $supplier))->not->toBe('No completed receipt history')
        ->and($onTime->invoke(null, $supplier))->toBe('1 / 1 on time');
});
