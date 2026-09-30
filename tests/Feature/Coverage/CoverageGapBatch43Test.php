<?php

declare(strict_types=1);

use App\Filament\Resources\Suppliers\Schemas\SupplierInfolist;
use App\Models\InventoryOperation;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierConfirmation;
use App\Models\SupplierProductReference;
use App\Services\Purchasing\PurchaseOrderWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('covers supplier backorder counts with numeric and non-numeric workflow quantities', function (): void {
    $supplier = Supplier::factory()->create();

    $first = PurchaseOrder::factory()->sent()->create([
        'supplier_id' => $supplier->getKey(),
    ]);
    $second = PurchaseOrder::factory()->sent()->create([
        'supplier_id' => $supplier->getKey(),
    ]);

    app()->instance(PurchaseOrderWorkflowService::class, new readonly class($first->getKey(), $second->getKey())
    {
        public function __construct(
            private int $firstId,
            private int $secondId,
        ) {}

        public function project(PurchaseOrder $order): \stdClass
        {
            return (object) [
                'backorderedBaseQuantity' => match ($order->getKey()) {
                    $this->firstId => '2.500000',
                    $this->secondId => 'not-numeric',
                    default => '0.000000',
                },
            ];
        }
    });

    $method = new ReflectionMethod(SupplierInfolist::class, 'openBackorderOrderCount');

    expect($method->invoke(null, $supplier))->toBe(1);
});

it('covers supplier response-time and duration summaries', function (): void {
    $supplier = Supplier::factory()->create();

    $average = new ReflectionMethod(SupplierInfolist::class, 'averageResponseTime');
    $duration = new ReflectionMethod(SupplierInfolist::class, 'durationSummary');

    expect($average->invoke(null, $supplier))->toBe('No responses recorded')
        ->and($duration->invoke(null, 45.0))->toBe('45 min')
        ->and($duration->invoke(null, 120.0))->toBe('2.0 hours')
        ->and($duration->invoke(null, 2880.0))->toBe('2.0 days');

    $confirmation = SupplierConfirmation::factory()->confirmed()->create([
        'supplier_id' => $supplier->getKey(),
    ]);
    $confirmation->forceFill([
        'created_at' => now()->subMinutes(90),
        'confirmed_at' => now(),
    ])->saveQuietly();

    expect($average->invoke(null, $supplier))->toBe('1.5 hours');
});

it('covers supplier receipt lead-time and on-time summaries', function (): void {
    $supplier = Supplier::factory()->create();

    $leadTime = new ReflectionMethod(SupplierInfolist::class, 'averageReceiptLeadTime');
    $onTime = new ReflectionMethod(SupplierInfolist::class, 'onTimeReceiptSummary');

    expect($leadTime->invoke(null, $supplier))->toBe('No completed receipt history')
        ->and($onTime->invoke(null, $supplier))->toBe('No completed POs with expected dates');

    $first = PurchaseOrder::factory()->received()->create([
        'supplier_id' => $supplier->getKey(),
        'ordered_at' => '2026-09-01',
        'expected_at' => '2026-09-03',
    ]);
    InventoryOperation::factory()->receipt()->done()->create([
        'source_document_type' => PurchaseOrder::class,
        'source_document_id' => $first->getKey(),
        'completed_at' => '2026-09-02 12:00:00',
    ]);

    $second = PurchaseOrder::factory()->received()->create([
        'supplier_id' => $supplier->getKey(),
        'ordered_at' => '2026-09-01',
        'expected_at' => '2026-09-01',
    ]);
    InventoryOperation::factory()->receipt()->done()->create([
        'source_document_type' => PurchaseOrder::class,
        'source_document_id' => $second->getKey(),
        'completed_at' => '2026-09-02 12:00:00',
    ]);

    PurchaseOrder::factory()->received()->create([
        'supplier_id' => $supplier->getKey(),
        'ordered_at' => '2026-09-01',
        'expected_at' => '2026-09-05',
    ]);

    expect($leadTime->invoke(null, $supplier))->toBeString()
        ->and($leadTime->invoke(null, $supplier))->not->toBe('No completed receipt history')
        ->and($onTime->invoke(null, $supplier))->toBe('1 / 2 on time');
});

it('covers supplier committed-value and product-variant summaries', function (): void {
    $supplier = Supplier::factory()->create();

    $committed = new ReflectionMethod(SupplierInfolist::class, 'committedValueByCurrency');
    $variantSummary = new ReflectionMethod(SupplierInfolist::class, 'supplierProductVariantSummary');

    expect($committed->invoke(null, $supplier))->toBe('No sent Purchase Orders');

    PurchaseOrder::factory()->sent()->create([
        'supplier_id' => $supplier->getKey(),
        'currency_code' => 'AED',
        'total_amount' => '125.50',
    ]);
    PurchaseOrder::factory()->sent()->create([
        'supplier_id' => $supplier->getKey(),
        'currency_code' => 'AED',
        'total_amount' => '74.50',
    ]);

    expect($committed->invoke(null, $supplier))->toBe('AED 200.00');

    $missing = new SupplierProductReference;
    $missing->setRelation('productVariant', null);

    expect($variantSummary->invoke(null, $missing))->toBe('Variant unavailable');

    $variant = new ProductVariant;
    $variant->name = 'Coverage Variant';
    $variant->sku = 'SKU-COV-43';

    $reference = new SupplierProductReference;
    $reference->setRelation('productVariant', $variant);

    expect($variantSummary->invoke(null, $reference))->toBe('Coverage Variant · SKU-COV-43');
});
