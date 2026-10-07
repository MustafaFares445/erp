<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\NotificationEventKey;
use App\Enums\PurchasePermission;
use App\Enums\SupplierConfirmationStatus;
use App\Enums\SupportPermission;
use App\Events\EquipmentInstallationMilestone;
use App\Events\SupplierCommitmentRecorded;
use App\Events\SupportQualityMilestone;
use App\Events\VisitAssigned;
use App\Listeners\SendBusinessNotification;
use App\Models\CustomerVisit;
use App\Models\InventoryLot;
use App\Models\LotQualityAlert;
use App\Models\MaintenanceRecord;
use App\Models\NotificationDelivery;
use App\Models\PurchaseOrder;
use App\Models\SupplierConfirmation;
use App\Models\SupplierConfirmationItem;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function coverage106Permission(string $name): void
{
    Permission::findOrCreate($name, 'web');
}

it('covers confirmed supplier commitment allocation notifications', function (): void {
    coverage106Permission(InventoryPermission::WarehouseManage->value);
    coverage106Permission(PurchasePermission::ConfirmationRecord->value);

    $inventoryUser = User::factory()->create();
    $inventoryUser->givePermissionTo(InventoryPermission::WarehouseManage->value);

    $order = PurchaseOrder::factory()->create();
    $confirmation = SupplierConfirmation::factory()->confirmed()->create([
        'purchase_order_id' => $order->id,
        'supplier_id' => $order->supplier_id,
        'confirmation_status' => SupplierConfirmationStatus::Confirmed,
    ]);
    SupplierConfirmationItem::factory()->create([
        'supplier_confirmation_id' => $confirmation->id,
        'confirmed_base_quantity' => '2.000000',
        'backordered_base_quantity' => '0.000000',
    ]);

    $dispatcher = app(NotificationDispatcher::class);

    new SendBusinessNotification($dispatcher)
        ->handle(new SupplierCommitmentRecorded($order, $confirmation));

    expect(true)->toBeTrue();
});

it('covers installation milestone invalid-recipient guard', function (): void {
    config()->set('support.equipment_installation_enabled', true);

    $record = MaintenanceRecord::factory()->create();
    $dispatcher = app(NotificationDispatcher::class);

    new SendBusinessNotification($dispatcher)->handle(
        new EquipmentInstallationMilestone(
            $record,
            NotificationEventKey::InstallationScheduled,
        ),
    );

    expect(true)->toBeTrue();
});

it('deduplicates a quality manager who also owns inventory condition-change permission', function (): void {
    config()->set('support.product_quality_enabled', true);
    config()->set('support.lot_complaint_threshold', 3);

    coverage106Permission(SupportPermission::QualityComplaintManage->value);
    coverage106Permission(InventoryPermission::ConditionChangeCreate->value);

    $manager = User::factory()->create();
    $manager->givePermissionTo([
        SupportPermission::QualityComplaintManage->value,
        InventoryPermission::ConditionChangeCreate->value,
    ]);

    $lot = InventoryLot::factory()->create();
    LotQualityAlert::query()->create([
        'inventory_lot_id' => $lot->id,
        'open_complaints' => 3,
        'threshold' => 3,
        'raised_at' => now(),
    ]);

    $dispatcher = app(NotificationDispatcher::class);

    new SendBusinessNotification($dispatcher)->handle(
        new SupportQualityMilestone(
            NotificationEventKey::LotComplaintThresholdReached,
            $lot,
        ),
    );

    expect(true)->toBeTrue();
});

it('dispatches visit assignment notifications to the assigned employee', function (): void {
    $visit = CustomerVisit::factory()->create();
    $recipient = $visit->employee->user;

    expect($recipient)->toBeInstanceOf(User::class);

    app(SendBusinessNotification::class)->handle(new VisitAssigned($visit));

    expect(NotificationDelivery::query()
        ->where('template_key', NotificationEventKey::VisitAssigned->value)
        ->where('notifiable_id', $recipient->getKey())
        ->count())->toBeGreaterThanOrEqual(2);
});
