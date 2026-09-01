<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierConfirmationStatus;
use App\Filament\Resources\PurchasingReports\PurchasingReportResource;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierConfirmation;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use App\Services\Purchasing\PurchasingReportService;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new PurchasePermissionSeeder)->run();
    $this->reports = app(PurchasingReportService::class);
    $this->receiving = app(PurchaseOrderReceivingService::class);
    $this->operations = app(InventoryOperationService::class);
    $this->manager = User::factory()->create();
    $this->manager->assignRole(DashboardRole::PurchasingManager->value);
    $this->actingAs($this->manager);
});

function reportOrder(
    PurchaseOrderStatus $status,
    float $quantity,
    string $unitCost,
    ?Supplier $supplier = null,
): PurchaseOrder {
    $order = PurchaseOrder::factory()->create([
        'status' => $status,
        'supplier_id' => ($supplier ?? Supplier::factory()->create())->getKey(),
        'destination_warehouse_id' => Warehouse::factory()->create()->getKey(),
        'sent_at' => now(),
    ]);

    $variant = ProductVariant::factory()->create();

    $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => $quantity,
        'unit_cost' => $unitCost,
        'line_total' => (float) $unitCost * $quantity,
    ]);

    return $order->refresh();
}

it('reconciles open commitments exactly against ordered minus received (SC-007)', function (): void {
    $supplier = Supplier::factory()->create();

    $order = reportOrder(PurchaseOrderStatus::Sent, 10, '5.00', $supplier);
    $order->lines()->firstOrFail()->forceFill(['quantity_received' => 4])->save();

    $rows = $this->reports->openCommitments();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['supplier'])->toBe($supplier->name)
        ->and($rows[0]['orders'])->toBe(1)
        ->and($rows[0]['ordered_value'])->toBe(50.0)
        ->and($rows[0]['received_value'])->toBe(20.0)
        ->and($rows[0]['outstanding_value'])->toBe(30.0);
});

it('excludes drafts and terminal orders from open commitments', function (): void {
    // A draft is not a commitment yet; a terminal order will never see more
    // stock, and a short-closed one had its remainder deliberately abandoned.
    foreach ([
        PurchaseOrderStatus::Draft,
        PurchaseOrderStatus::Received,
        PurchaseOrderStatus::Closed,
        PurchaseOrderStatus::Cancelled,
    ] as $status) {
        reportOrder($status, 10, '5.00');
    }

    expect($this->reports->openCommitments())->toBe([]);
});

it('includes every non-terminal committed status in open commitments', function (): void {
    foreach ([
        PurchaseOrderStatus::PendingApproval,
        PurchaseOrderStatus::Approved,
        PurchaseOrderStatus::Rejected,
        PurchaseOrderStatus::Sent,
        PurchaseOrderStatus::PartiallyReceived,
    ] as $status) {
        reportOrder($status, 2, '10.00');
    }

    $total = array_sum(array_column($this->reports->openCommitments(), 'outstanding_value'));

    expect($total)->toBe(100.0);
});

it('groups several orders for one supplier into a single row', function (): void {
    $supplier = Supplier::factory()->create();

    reportOrder(PurchaseOrderStatus::Sent, 3, '10.00', $supplier);
    reportOrder(PurchaseOrderStatus::Sent, 2, '10.00', $supplier);

    $rows = $this->reports->openCommitments();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['orders'])->toBe(2)
        ->and($rows[0]['ordered_value'])->toBe(50.0);
});

it('excludes a soft-deleted order from open commitments', function (): void {
    reportOrder(PurchaseOrderStatus::Sent, 10, '5.00')->delete();

    expect($this->reports->openCommitments())->toBe([]);
});

it('scores receiving performance against the promised date, not the buyer hope', function (): void {
    $supplier = Supplier::factory()->create();
    $order = reportOrder(PurchaseOrderStatus::Sent, 5, '4.00', $supplier);

    SupplierConfirmation::factory()->create([
        'confirmable_type' => PurchaseOrder::class,
        'confirmable_id' => $order->getKey(),
        'supplier_id' => $supplier->getKey(),
        'confirmation_status' => SupplierConfirmationStatus::Confirmed,
        'promised_at' => today()->addWeek()->toDateString(),
        'confirmed_at' => now(),
    ]);

    $operation = $this->receiving->initiate($this->manager, $order);
    $this->operations->markReady($operation, $this->manager);
    $this->operations->complete($operation->refresh(), $this->manager);

    $rows = $this->reports->receivingPerformance();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['promised'])->toBe(1)
        ->and($rows[0]['on_time'])->toBe(1)
        ->and($rows[0]['on_time_rate'])->toBe(100.0);
});

it('counts a delivery after the promised date as late', function (): void {
    $supplier = Supplier::factory()->create();
    $order = reportOrder(PurchaseOrderStatus::Sent, 5, '4.00', $supplier);

    SupplierConfirmation::factory()->create([
        'confirmable_type' => PurchaseOrder::class,
        'confirmable_id' => $order->getKey(),
        'supplier_id' => $supplier->getKey(),
        'confirmation_status' => SupplierConfirmationStatus::Confirmed,
        'promised_at' => today()->subWeek()->toDateString(),
        'confirmed_at' => now(),
    ]);

    $operation = $this->receiving->initiate($this->manager, $order);
    $this->operations->markReady($operation, $this->manager);
    $this->operations->complete($operation->refresh(), $this->manager);

    $rows = $this->reports->receivingPerformance();

    expect($rows[0]['on_time'])->toBe(0)
        ->and($rows[0]['on_time_rate'])->toBe(0.0);
});

it('excludes an order with no confirmed promise rather than counting it as on time', function (): void {
    // There is nothing to have missed, so scoring it either way would be a
    // fabricated number.
    $order = reportOrder(PurchaseOrderStatus::Sent, 5, '4.00');

    $operation = $this->receiving->initiate($this->manager, $order);
    $this->operations->markReady($operation, $this->manager);
    $this->operations->complete($operation->refresh(), $this->manager);

    expect($this->reports->receivingPerformance())->toBe([]);
});

it('reports only lines whose received cost differed from the ordered cost', function (): void {
    $matching = reportOrder(PurchaseOrderStatus::Sent, 4, '10.00');
    $matching->lines()->firstOrFail()->forceFill([
        'quantity_received' => 4,
        'last_received_unit_cost' => '10.00',
    ])->save();

    $varied = reportOrder(PurchaseOrderStatus::Sent, 4, '10.00');
    $varied->lines()->firstOrFail()->forceFill([
        'quantity_received' => 4,
        'last_received_unit_cost' => '12.50',
    ])->save();

    $rows = $this->reports->costVariance();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['purchase_order_number'])->toBe($varied->purchase_order_number)
        ->and($rows[0]['ordered_cost'])->toBe(10.0)
        ->and($rows[0]['received_cost'])->toBe(12.5)
        ->and($rows[0]['variance'])->toBe(2.5);
});

it('omits a line that has never been received from cost variance', function (): void {
    // A line with no actual cost has nothing to compare against, and showing it
    // at zero variance would suggest a match that has not happened.
    reportOrder(PurchaseOrderStatus::Sent, 4, '10.00');

    expect($this->reports->costVariance())->toBe([]);
});

it('gates the report surface on the same permission as its export (SC-007)', function (): void {
    $reviewer = User::factory()->create();
    $reviewer->assignRole(DashboardRole::Reviewer->value);

    $officer = User::factory()->create();
    $officer->assignRole(DashboardRole::PurchasingOfficer->value);

    $this->actingAs($this->manager);
    expect(PurchasingReportResource::canAccess())->toBeTrue();

    $this->actingAs($reviewer);
    expect(PurchasingReportResource::canAccess())->toBeTrue();

    // An officer has no report permission, so neither the page nor the export
    // that reads the same data is reachable.
    $this->actingAs($officer);
    expect(PurchasingReportResource::canAccess())->toBeFalse();
});

it('never offers the report surface for creation', function (): void {
    expect(PurchasingReportResource::canCreate())->toBeFalse();
});
