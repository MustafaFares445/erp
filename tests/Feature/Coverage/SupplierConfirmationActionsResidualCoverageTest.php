<?php

declare(strict_types=1);

use App\Enums\SupplierConfirmationStatus;
use App\Filament\Resources\SupplierConfirmations\Actions\SupplierConfirmationActions;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\SupplierConfirmation;
use App\Models\SupplierConfirmationItem;
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
        ->toThrow(\LogicException::class, 'must have a numeric identifier');
});
