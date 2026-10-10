<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\PurchaseOrderStatus;
use App\Models\Bill;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\PurchaseInbound;
use App\Models\PurchaseOrder;
use App\Models\PurchaseSetting;
use App\Models\SupplierConfirmation;
use App\Models\Unit;
use App\Models\User;
use App\Services\Purchasing\PurchaseOrderApprovalService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Explicit base case: the isolated snapshot's nested path is not recognized
// by Pest's global ->in('Feature') mapping on this Windows installation.
uses(TestCase::class, RefreshDatabase::class);

it('pins Purchasing approval, first Send, and downstream stock and AP boundaries', function (): void {
    (new ChartOfAccountsSeeder)->run();
    (new PurchasePermissionSeeder)->run();
    PurchaseSetting::factory()->threshold('10.00')->create();

    $officer = User::factory()->create();
    $officer->assignRole(DashboardRole::PurchasingOfficer->value);

    $manager = User::factory()->create();
    $manager->assignRole(DashboardRole::PurchasingManager->value);

    $unit = Unit::factory()->create();
    $variant = ProductVariant::factory()->create(['unit_id' => $unit->getKey()]);
    $po = PurchaseOrder::factory()->create([
        'currency_code' => 'AED',
        'total_amount' => '500.00',
    ]);
    $line = $po->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity_ordered' => '2',
        'unit_cost' => '250.00',
    ]);
    $line->forceFill([
        'transaction_quantity' => '2.000000',
        'transaction_unit_id' => $unit->getKey(),
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '2.000000',
        'received_base_quantity' => '0.000000',
        'line_total' => '500.00',
    ])->save();

    $approval = app(PurchaseOrderApprovalService::class);
    $submitted = $approval->submit($officer, $po);
    $accepted = $approval->approve($manager, $submitted);

    expect($accepted->status)->toBe(PurchaseOrderStatus::Accepted)
        ->and($accepted->sent_at)->toBeNull()
        ->and(PurchaseInbound::query()->where('purchase_order_id', $po->id)->count())->toBe(0)
        ->and(Bill::query()->where('purchase_order_id', $po->id)->count())->toBe(0)
        ->and(InventoryOperation::query()->count())->toBe(0)
        ->and(InventoryMovement::query()->count())->toBe(0);

    $sent = $approval->send($manager, $accepted);
    $bill = Bill::query()->where('purchase_order_id', $sent->id)->sole();
    expect($sent->sent_at)->not->toBeNull()
        ->and(PurchaseInbound::query()->where('purchase_order_id', $po->id)->count())->toBe(1)
        ->and($bill->status->value)->toBe('draft')
        ->and(InventoryOperation::query()->count())->toBe(0)
        ->and(InventoryMovement::query()->count())->toBe(0)
        ->and(InventoryStock::query()->count())->toBe(0)
        ->and(SupplierConfirmation::query()->where('purchase_order_id', $po->id)->count())->toBe(0);

    $approval->send($manager, $sent->refresh());
    expect(PurchaseInbound::query()->where('purchase_order_id', $po->id)->count())->toBe(1)
        ->and(Bill::query()->where('purchase_order_id', $po->id)->count())->toBe(1);
});
