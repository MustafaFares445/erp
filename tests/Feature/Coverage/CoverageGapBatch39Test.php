<?php

declare(strict_types=1);

use App\Enums\PurchaseOrderDocument;
use App\Filament\Pages\PurchaseNeeds;
use App\Filament\Resources\PurchaseInbounds\Pages\ViewPurchaseInbound;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Models\Currency;
use App\Models\ProductVariant;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseInboundLine;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('covers purchase inbound scalar workflow helpers and actor guard', function (): void {
    $integerInput = new ReflectionMethod(ViewPurchaseInbound::class, 'integerInput');
    $quantityInput = new ReflectionMethod(ViewPurchaseInbound::class, 'quantityInput');
    $positive = new ReflectionMethod(ViewPurchaseInbound::class, 'isPositiveQuantity');
    $actor = new ReflectionMethod(ViewPurchaseInbound::class, 'actor');

    expect($integerInput->invoke(null, 12))->toBe(12)
        ->and($integerInput->invoke(null, '13'))->toBe(13)
        ->and($quantityInput->invoke(null, 4))->toBe('4')
        ->and($quantityInput->invoke(null, 4.25))->toBe('4.25')
        ->and($quantityInput->invoke(null, '6.500000'))->toBe('6.500000')
        ->and($positive->invoke(null, '0.000001'))->toBeFalse()
        ->and($positive->invoke(null, '0.000002'))->toBeTrue()
        ->and($positive->invoke(null, 'not-numeric'))->toBeFalse();

    expect(fn (): mixed => $integerInput->invoke(null, '12.5'))
        ->toThrow(LogicException::class, 'integer workflow identifier')
        ->and(fn (): mixed => $quantityInput->invoke(null, []))
        ->toThrow(LogicException::class, 'numeric inbound quantity')
        ->and(fn (): mixed => $actor->invoke(null))
        ->toThrow(LogicException::class, 'authenticated Inventory user');

    $user = User::factory()->create();
    $this->actingAs($user);

    expect($actor->invoke(null))->toBe($user);
});

it('covers receivable purchase inbound allocation option filtering', function (): void {
    $inbound = PurchaseInbound::factory()->create();
    $line = PurchaseInboundLine::factory()->create([
        'purchase_inbound_id' => $inbound->getKey(),
    ]);

    $warehouse = Warehouse::factory()->create(['name' => 'Coverage Warehouse']);

    $nullQuantity = PurchaseInboundAllocation::factory()->create([
        'purchase_inbound_line_id' => $line->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'allocated_base_quantity' => null,
    ]);
    $zeroQuantity = PurchaseInboundAllocation::factory()->create([
        'purchase_inbound_line_id' => $line->getKey(),
        'warehouse_id' => Warehouse::factory(),
        'allocated_base_quantity' => '0.000000',
    ]);
    $receivableWarehouse = Warehouse::factory()->create(['name' => 'Receivable Warehouse']);
    $receivable = PurchaseInboundAllocation::factory()->create([
        'purchase_inbound_line_id' => $line->getKey(),
        'warehouse_id' => $receivableWarehouse->getKey(),
        'allocated_base_quantity' => '3.500000',
    ]);

    $method = new ReflectionMethod(ViewPurchaseInbound::class, 'receivableAllocationOptions');
    $options = $method->invoke(null, $inbound);

    expect($options)
        ->not->toHaveKey($nullQuantity->getKey())
        ->not->toHaveKey($zeroQuantity->getKey())
        ->toHaveKey($receivable->getKey())
        ->and($options[$receivable->getKey()])->toContain('Receivable Warehouse');
});

it('covers purchase needs default currency fallback and supplier-product counts', function (): void {
    $defaultCurrency = new ReflectionMethod(PurchaseNeeds::class, 'defaultCurrencyCode');
    $counts = new ReflectionMethod(PurchaseNeeds::class, 'eligibleSupplierCounts');

    expect($defaultCurrency->invoke(null))->toBe('AED')
        ->and($counts->invoke(null, []))->toBe([]);

    Currency::query()->update(['is_default' => false]);
    Currency::query()->where('code', 'USD')->update([
        'is_active' => true,
        'is_default' => true,
    ]);

    expect($defaultCurrency->invoke(null))->toBe('USD');

    $variantA = ProductVariant::factory()->create();
    $variantB = ProductVariant::factory()->create();
    $activeSupplier = Supplier::factory()->create(['is_active' => true]);
    $secondSupplier = Supplier::factory()->create(['is_active' => true]);
    $inactiveSupplier = Supplier::factory()->create(['is_active' => false]);

    SupplierProductReference::factory()->create([
        'supplier_id' => $activeSupplier->getKey(),
        'product_variant_id' => $variantA->getKey(),
        'is_active' => true,
    ]);
    SupplierProductReference::factory()->create([
        'supplier_id' => $secondSupplier->getKey(),
        'product_variant_id' => $variantA->getKey(),
        'is_active' => true,
    ]);
    SupplierProductReference::factory()->create([
        'supplier_id' => $inactiveSupplier->getKey(),
        'product_variant_id' => $variantA->getKey(),
        'is_active' => true,
    ]);
    SupplierProductReference::factory()->create([
        'supplier_id' => $activeSupplier->getKey(),
        'product_variant_id' => $variantB->getKey(),
        'is_active' => false,
    ]);

    expect($counts->invoke(null, [$variantA->getKey(), $variantB->getKey()]))->toBe([
        $variantA->getKey() => 2,
        $variantB->getKey() => 0,
    ]);
});

it('extracts uploaded purchase order documents and removes raw upload fields', function (): void {
    $reflection = new ReflectionClass(CreatePurchaseOrder::class);
    $page = $reflection->newInstanceWithoutConstructor();
    $extract = $reflection->getMethod('extractDocuments');

    $data = [
        PurchaseOrderDocument::CustomsPayment->value => ['tmp/customs-payment.pdf'],
        PurchaseOrderDocument::CustomsClearanceDocument->value => [],
        'supplier_id' => 15,
    ];
    $args = [&$data];

    $documents = $extract->invokeArgs($page, $args);

    expect($documents)->toBe([
        PurchaseOrderDocument::CustomsPayment->value => 'tmp/customs-payment.pdf',
    ])->and($data)->toBe(['supplier_id' => 15]);

    $data = [
        PurchaseOrderDocument::CustomsPayment->value => 'not-an-upload-array',
        PurchaseOrderDocument::CustomsClearanceDocument->value => [42],
        'notes' => 'keep me',
    ];
    $args = [&$data];

    expect($extract->invokeArgs($page, $args))->toBe([])
        ->and($data)->toBe(['notes' => 'keep me']);
});
