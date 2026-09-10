<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\Domain\DuplicateSupplierReference;
use App\Models\Bill;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\ProductVariant;
use App\Models\PurchaseInbound;
use App\Models\PurchaseOrder;
use App\Models\PurchaseSetting;
use App\Models\SupplierProductReference;
use App\Models\Unit;
use App\Models\User;
use App\Services\Purchasing\PurchaseOrderApprovalService;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new PurchasePermissionSeeder)->run();

    $this->approval = app(PurchaseOrderApprovalService::class);
    $this->manager = User::factory()->create();
    $this->manager->assignRole(DashboardRole::PurchasingManager->value);
    $this->officer = User::factory()->create();
    $this->officer->assignRole(DashboardRole::PurchasingOfficer->value);
});

function acceptanceOrder(string $total = '500.00'): PurchaseOrder
{
    $order = PurchaseOrder::factory()->create([
        'currency_code' => 'AED',
        'total_amount' => $total,
    ]);

    $line = $order->lines()->create([
        'product_variant_id' => ProductVariant::factory()->create()->getKey(),
        'unit_id' => Unit::factory()->create()->getKey(),
        'quantity_ordered' => 2,
        'unit_cost' => number_format(((float) $total) / 2, 2, '.', ''),
        'line_total' => $total,
    ]);

    $line->forceFill(['conversion_factor_snapshot' => '1.000000'])->save();

    return $order->refresh();
}

it('atomically creates the non-physical cross-module side effects when a purchase order is accepted', function (): void {
    PurchaseSetting::factory()->threshold('10.00')->create();

    $order = acceptanceOrder();
    $submitted = $this->approval->submit($this->officer, $order);
    $approved = $this->approval->approve($this->manager, $submitted);

    expect($approved->status)->toBe(PurchaseOrderStatus::Accepted)
        ->and(InventoryOperation::query()->count())->toBe(0)
        ->and(InventoryMovement::query()->count())->toBe(0)
        ->and(PurchaseInbound::query()->where('purchase_order_id', $approved->getKey())->count())->toBe(1)
        ->and($approved->purchaseInbound()->firstOrFail()->lines()->count())->toBe($approved->lines()->count())
        ->and(SupplierProductReference::query()
            ->where('supplier_id', $approved->supplier_id)
            ->where('product_variant_id', $approved->lines()->firstOrFail()->product_variant_id)
            ->count())->toBe(1)
        ->and(Bill::query()->where('purchase_order_id', $approved->getKey())->count())->toBe(1);

    $bill = Bill::query()->where('purchase_order_id', $approved->getKey())->sole();
    $poLine = $approved->lines()->firstOrFail();

    expect($bill->status->value)->toBe('draft')
        ->and($bill->supplier_id)->toBeNull()
        ->and($bill->resolved_supplier_id)->toBe($approved->supplier_id)
        ->and($bill->lines()->count())->toBe($approved->lines()->count())
        ->and($bill->lines()->firstOrFail()->purchase_order_line_id)->toBe($poLine->getKey());
});

it('rolls the acceptance and every downstream side effect back when draft bill provisioning fails', function (): void {
    PurchaseSetting::factory()->threshold('10.00')->create();

    $order = acceptanceOrder();
    $submitted = $this->approval->submit($this->officer, $order);

    Bill::factory()->create([
        'supplier_id' => $submitted->supplier_id,
        'purchase_order_id' => null,
        'supplier_reference' => 'PO-AUTO:'.$submitted->purchase_order_number,
    ]);

    expect(fn (): PurchaseOrder => $this->approval->approve($this->manager, $submitted))
        ->toThrow(DuplicateSupplierReference::class);

    expect($submitted->refresh()->status)->toBe(PurchaseOrderStatus::PendingApproval)
        ->and(PurchaseInbound::query()->where('purchase_order_id', $submitted->getKey())->count())->toBe(0)
        ->and(SupplierProductReference::query()
            ->where('supplier_id', $submitted->supplier_id)
            ->where('product_variant_id', $submitted->lines()->firstOrFail()->product_variant_id)
            ->count())->toBe(0)
        ->and(Bill::query()->where('purchase_order_id', $submitted->getKey())->count())->toBe(0)
        ->and(InventoryOperation::query()->count())->toBe(0)
        ->and(InventoryMovement::query()->count())->toBe(0);
});
