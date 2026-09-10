<?php

declare(strict_types=1);

use App\Enums\AccountingPermission;
use App\Enums\InventoryPermission;
use App\Enums\NotificationEventKey;
use App\Events\PurchaseOrderAccepted;
use App\Listeners\SendBusinessNotification;
use App\Models\Bill;
use App\Models\NotificationDelivery;
use App\Models\PurchaseOrder;
use App\Models\User;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\PurchaseOrderNotificationTemplateSeeder;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
    (new AccountingPermissionSeeder)->run();
    (new PurchaseOrderNotificationTemplateSeeder)->run();
    Notification::fake();
});

it('dispatches the accepted PO event only after a transaction commits', function (): void {
    $order = PurchaseOrder::factory()->accepted()->create();
    $bill = Bill::factory()->forPurchaseOrder($order)->create();

    expect(new PurchaseOrderAccepted($order, $bill))
        ->toBeInstanceOf(ShouldDispatchAfterCommit::class);
});

it('routes allocation and draft bill notifications only to users owning those responsibilities', function (): void {
    $inventoryUser = User::factory()->create();
    $inventoryUser->givePermissionTo(InventoryPermission::WarehouseManage->value);

    $accountingUser = User::factory()->create();
    $accountingUser->givePermissionTo(AccountingPermission::BillManage->value);

    $unrelatedUser = User::factory()->create();

    $order = PurchaseOrder::factory()->accepted()->create();
    $bill = Bill::factory()->forPurchaseOrder($order)->create();

    app(SendBusinessNotification::class)->handle(new PurchaseOrderAccepted($order, $bill));

    expect(NotificationDelivery::query()
        ->where('notifiable_id', $inventoryUser->getKey())
        ->where('template_key', NotificationEventKey::PurchaseOrderReadyForAllocation->value)
        ->count())->toBe(1)
        ->and(NotificationDelivery::query()
            ->where('notifiable_id', $accountingUser->getKey())
            ->where('template_key', NotificationEventKey::PurchaseOrderDraftBillReady->value)
            ->count())->toBe(1)
        ->and(NotificationDelivery::query()
            ->where('notifiable_id', $unrelatedUser->getKey())
            ->count())->toBe(0);
});
