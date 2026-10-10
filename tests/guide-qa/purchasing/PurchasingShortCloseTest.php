<?php

declare(strict_types=1);

use App\Enums\BillStatus;
use App\Enums\DashboardRole;
use App\Enums\OperationStage;
use App\Enums\PurchaseOrderStatus;
use App\Models\Bill;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Purchasing\Exceptions\PurchaseOrderNotCancellable;
use App\Services\Purchasing\PurchaseOrderApprovalService;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    (new PurchasePermissionSeeder)->run();
    $this->buyer = User::factory()->create();
    $this->buyer->assignRole(DashboardRole::PurchasingManager->value);
    $this->actingAs($this->buyer);
});

it('short-closes only the outstanding purchase commitment with a recorded reason', function (): void {
    $order = PurchaseOrder::factory()->partiallyReceived()->create();
    $initialSentAt = $order->sent_at;
    $closed = app(PurchaseOrderApprovalService::class)
        ->close($this->buyer, $order, 'Supplier can no longer deliver the remainder');
    expect($closed->status)->toBe(PurchaseOrderStatus::Closed)
        ->and($closed->closure_reason)->toBe('Supplier can no longer deliver the remainder')
        ->and($closed->closed_at)->not->toBeNull()
        ->and($closed->sent_at?->equalTo($initialSentAt))->toBeTrue();
});

it('rejects short-close while a ready Inventory receipt is still open', function (): void {
    // Verify the service guard even if an earlier authorization check is bypassed.
    Gate::before(static fn (): bool => true);
    $order = PurchaseOrder::factory()->partiallyReceived()->create();
    $receipt = $order->receipts()->create([
        'operation_type' => 'receipt',
        'destination_warehouse_id' => Warehouse::factory()->create()->id,
        'supplier_id' => $order->supplier_id,
    ]);
    $receipt->forceFill(['stage' => OperationStage::Ready])->save();

    expect(fn (): PurchaseOrder => app(PurchaseOrderApprovalService::class)
        ->close($this->buyer, $order->refresh(), 'Outstanding stock no longer needed'))
        ->toThrow(PurchaseOrderNotCancellable::class, 'active Inventory receipt');

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::PartiallyReceived)
        ->and($order->fresh()->closed_at)->toBeNull()
        ->and($receipt->fresh()->stage)->toBe(OperationStage::Ready);
});

it('cancels only draft supplier bills, while an approved bill blocks PO cancellation', function (): void {
    $cancellable = PurchaseOrder::factory()->sent()->create();
    $draft = Bill::factory()->forPurchaseOrder($cancellable)->create(['status' => BillStatus::Draft->value]);

    $cancelled = app(PurchaseOrderApprovalService::class)
        ->cancel($this->buyer, $cancellable, 'Duplicate supplier commitment');

    expect($cancelled->status)->toBe(PurchaseOrderStatus::Cancelled)
        ->and($draft->fresh()->status)->toBe(BillStatus::Cancelled);

    Gate::before(static fn (): bool => true);
    $blocked = PurchaseOrder::factory()->sent()->create();
    $approved = Bill::factory()->forPurchaseOrder($blocked)->create(['status' => BillStatus::Approved->value]);
    expect(fn (): PurchaseOrder => app(PurchaseOrderApprovalService::class)
        ->cancel($this->buyer, $blocked, 'Do not discard financial posting'))
        ->toThrow(PurchaseOrderNotCancellable::class, 'approved supplier bill');
    expect($blocked->fresh()->status)->toBe(PurchaseOrderStatus::Accepted)
        ->and($approved->fresh()->status)->toBe(BillStatus::Approved);
});
