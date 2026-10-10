<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\SupplierConfirmationStatus;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\SupplierConfirmation;
use App\Models\User;
use App\Services\Purchasing\PurchaseOrderSupplierCommitmentService;
use App\Services\Purchasing\SupplierConfirmationService;
use Carbon\CarbonImmutable;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    (new PurchasePermissionSeeder)->run();
    $this->officer = User::factory()->create();
    $this->officer->assignRole(DashboardRole::PurchasingOfficer->value);
    $this->actingAs($this->officer);
});

function purchasingCommitmentOrder(string $quantity = '10'): PurchaseOrder
{
    $order = PurchaseOrder::factory()->sent()->create([
        'supplier_confirmation_required' => true,
    ]);
    $order->supplier()->update(['requires_confirmation' => true]);
    $variant = ProductVariant::factory()->create();
    $order->lines()->create([
        'product_variant_id' => $variant->id,
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => $quantity,
        'unit_cost' => '5.00',
    ]);

    return $order->refresh();
}

it('requires a sent supplier order before any supplier confirmation evidence can be requested', function (): void {
    $order = purchasingCommitmentOrder();
    $order->forceFill(['sent_at' => null])->save();
    expect(fn (): SupplierConfirmation => app(SupplierConfirmationService::class)
        ->recordPurchaseOrder($this->officer, $order))
        ->toThrow(ValidationException::class, 'Send the Purchase Order to the supplier');
    expect(SupplierConfirmation::query()->where('purchase_order_id', $order->id)->exists())->toBeFalse()
        ->and(InventoryMovement::query()->count())->toBe(0);
});

it('records real partial supplier commitment then follows up backordered quantity without touching stock', function (): void {
    $order = purchasingCommitmentOrder('10');
    $service = app(SupplierConfirmationService::class);
    $first = $service->recordPurchaseOrder($this->officer, $order, 'Asked supplier for stock');
    $item = $first->items->sole();
    expect($first->confirmation_status)->toBe(SupplierConfirmationStatus::Pending)
        ->and((float) $item->requested_base_quantity)->toBe(10.0);

    $responded = $service->respond(
        $this->officer, $first, SupplierConfirmationStatus::Partial,
        CarbonImmutable::parse($order->ordered_at)->addWeek(),
        'Supplier can ship six now; four backordered',
        [['id' => $item->id, 'confirmed_base_quantity' => '6', 'backordered_base_quantity' => '4']],
    );
    expect($responded->confirmation_status)->toBe(SupplierConfirmationStatus::Partial);

    $quantities = app(PurchaseOrderSupplierCommitmentService::class)->quantities($order->lines()->sole()->fresh());
    expect($quantities['confirmed'])->toBe('6.000000')
        ->and($quantities['backordered'])->toBe('4.000000');

    $followUp = $service->recordPurchaseOrder($this->officer, $order->refresh(), 'Second supplier commitment');
    expect((float) $followUp->items->sole()->requested_base_quantity)->toBe(4.0)
        ->and($followUp->confirmation_status)->toBe(SupplierConfirmationStatus::Pending)
        ->and($first->fresh()->confirmation_status)->toBe(SupplierConfirmationStatus::Partial)
        ->and(SupplierConfirmation::query()->where('purchase_order_id', $order->id)->count())->toBe(2)
        ->and(InventoryMovement::query()->count())->toBe(0);
});
