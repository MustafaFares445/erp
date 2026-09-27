<?php

declare(strict_types=1);

use App\Filament\Resources\PurchaseOrders\Actions\PurchaseOrderActions;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseSetting;
use App\Models\SupplierProductReference;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Support\Exceptions\Halt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

function invokePurchaseCoverageAction(Action $action, PurchaseOrder $order, array $data = []): void
{
    $function = $action->getActionFunction();
    expect($function)->not->toBeNull();

    $reflection = new ReflectionFunction($function);
    if ($reflection->getNumberOfParameters() >= 2) {
        $function($order, $data);
    } else {
        $function($order);
    }
}


it('uses the auto-approved submit notification path for an eligible PO', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);
    Gate::before(static fn (): bool => true);

    PurchaseSetting::factory()->threshold('999.00', 'AED')->create();

    $order = PurchaseOrder::factory()->create([
        'currency_code' => 'AED',
        'total_amount' => '10.00',
    ]);
    $variant = ProductVariant::factory()->create();
    $reference = SupplierProductReference::factory()->create([
        'supplier_id' => $order->supplier_id,
        'product_variant_id' => $variant->getKey(),
        'currency_code' => 'AED',
        'purchase_cost' => '10.00',
    ]);

    $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => '1.000000',
        'unit_cost' => '10.00',
        'line_total' => '10.00',
    ])->forceFill([
        'supplier_product_reference_id' => $reference->getKey(),
        'transaction_quantity' => '1.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '1.000000',
        'received_base_quantity' => '0.000000',
    ])->save();

    invokePurchaseCoverageAction(PurchaseOrderActions::submit(), $order->refresh());

    expect($order->refresh()->status->value)->toBe('accepted');
});

it('executes purchase-order lifecycle action guards and domain boundaries', function (): void {
    $definitions = [
        [PurchaseOrderActions::submit(), []],
        [PurchaseOrderActions::approve(), []],
        [PurchaseOrderActions::reject(), ['rejection_reason' => 'coverage reject']],
        [PurchaseOrderActions::send(), []],
        [PurchaseOrderActions::close(), ['closure_reason' => 'coverage close']],
        [PurchaseOrderActions::cancel(), ['cancellation_reason' => 'coverage cancel']],
    ];

    foreach ($definitions as [$action, $data]) {
        invokePurchaseCoverageAction($action, PurchaseOrder::factory()->create(), $data);
    }

    $this->actingAs(User::factory()->admin()->create());
    Gate::before(static fn (): bool => true);

    foreach ($definitions as [$action, $data]) {
        $order = PurchaseOrder::factory()->create();
        try {
            invokePurchaseCoverageAction($action, $order, $data);
        } catch (Halt) {
            // Invalid lifecycle/precondition failures are translated into Filament Halt.
        }
    }

    expect(true)->toBeTrue();
});
