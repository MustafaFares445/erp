<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\OperationStage;
use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Purchasing\PurchaseOrderApprovalService;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('refuses cancellation with an open Inventory receipt and preserves the purchase order', function (): void {
    (new PurchasePermissionSeeder)->run();
    $manager = User::factory()->create();
    $manager->assignRole(DashboardRole::PurchasingManager->value);
    $this->actingAs($manager);

    $po = PurchaseOrder::factory()->sent()->create();
    $receipt = $po->receipts()->create([
        'operation_type' => 'receipt',
        'destination_warehouse_id' => Warehouse::factory()->create()->id,
        'supplier_id' => $po->supplier_id,
    ]);
    $receipt->forceFill(['stage' => OperationStage::Ready])->save();

    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::Accepted)
        ->and($po->hasOpenReceipt())->toBeTrue()
        ->and($manager->can('cancel', $po->refresh()))->toBeFalse()
        ->and(fn (): PurchaseOrder => app(PurchaseOrderApprovalService::class)
            ->cancel($manager, $po->refresh(), 'Order no longer required'))
        ->toThrow(AuthorizationException::class);

    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::Accepted)
        ->and($po->fresh()->cancelled_at)->toBeNull()
        ->and($receipt->fresh()->stage)->toBe(OperationStage::Ready);
});
