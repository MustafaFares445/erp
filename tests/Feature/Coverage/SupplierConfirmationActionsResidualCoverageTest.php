<?php

declare(strict_types=1);

use App\Enums\SupplierConfirmationStatus;
use App\Filament\Resources\SupplierConfirmations\Actions\SupplierConfirmationActions;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\SupplierConfirmation;
use App\Models\SupplierConfirmationItem;
use App\Models\SupplierProductReference;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders confirmation defaults when no supplier commercial reference is linked', function (): void {
    $order = PurchaseOrder::factory()->sent()->create();
    $confirmation = SupplierConfirmation::factory()->create([
        'purchase_order_id' => $order->getKey(),
        'supplier_id' => $order->supplier_id,
    ]);

    $variant = ProductVariant::factory()->create();

    $item = $confirmation->items()->create([
        'product_variant_id' => $variant->getKey(),
        'purchase_order_line_id' => null,
        'requested_quantity' => '2.000000',
        'requested_base_quantity' => '2.000000',
        'confirmation_status' => SupplierConfirmationStatus::Pending,
    ]);

    $method = new ReflectionMethod(SupplierConfirmationActions::class, 'commitmentDefaults');
    /** @var list<array<string, mixed>> $defaults */
    $defaults = $method->invoke(null, $confirmation->refresh());

    expect($defaults)->toHaveCount(1)
        ->and($defaults[0]['id'])->toBe($item->getKey())
        ->and($defaults[0]['supplier_reference'])->toBe('—');
});

it('rejects an unsaved supplier confirmation item without a numeric identifier', function (): void {
    $method = new ReflectionMethod(SupplierConfirmationActions::class, 'itemId');

    expect(fn (): mixed => $method->invoke(null, new SupplierConfirmationItem))
        ->toThrow(LogicException::class, 'must have a numeric identifier');
});

it('renders the linked supplier commercial reference in confirmation defaults', function (): void {
    $order = PurchaseOrder::factory()->sent()->create();
    $confirmation = SupplierConfirmation::factory()->create([
        'purchase_order_id' => $order->getKey(),
        'supplier_id' => $order->supplier_id,
    ]);
    $variant = ProductVariant::factory()->create();
    $reference = SupplierProductReference::factory()->create([
        'supplier_id' => $order->supplier_id,
        'product_variant_id' => $variant->getKey(),
        'supplier_name' => 'Coverage Supplier',
        'supplier_item_number' => 'SUP-COV-140',
    ]);
    $line = $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => '2.000000',
        'unit_cost' => '10.00',
        'supplier_product_reference_id' => $reference->getKey(),
    ]);
    $confirmation->items()->create([
        'product_variant_id' => $variant->getKey(),
        'purchase_order_line_id' => $line->getKey(),
        'requested_quantity' => '2.000000',
        'requested_base_quantity' => '2.000000',
        'confirmation_status' => SupplierConfirmationStatus::Pending,
    ]);

    $defaults = (new ReflectionMethod(SupplierConfirmationActions::class, 'commitmentDefaults'))
        ->invoke(null, $confirmation->refresh());

    expect($defaults)->toHaveCount(1)
        ->and($defaults[0]['supplier_reference'])->toContain('SUP-COV-140');
});
