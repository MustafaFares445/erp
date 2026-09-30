<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use App\Enums\ReplenishmentCoverageSourceType;
use App\Enums\ReplenishmentCoverageStatus;
use App\Enums\ReplenishmentRequirementStatus;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseInboundLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\ReplenishmentCoverage;
use App\Models\ReplenishmentRequirement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseReplenishmentPolicy;
use App\Services\Payments\CustomerDepositApplicationService;
use App\Services\Supply\PurchaseReplenishmentCoverageService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('skips reversing a customer-deposit application that already has a reversal', function (): void {
    $customer = CustomerProfile::factory()->create();
    $payment = Payment::factory()->create([
        'payment_number' => 'PAY-COV-059',
        'customer_id' => $customer->getKey(),
        'payment_method_id' => PaymentMethod::factory(),
        'amount' => '50.00',
        'currency' => 'AED',
        'payment_date' => today(),
        'status' => PaymentStatus::Posted->value,
        'posted_at' => now(),
    ]);
    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'status' => 'issued',
        'issued_at' => now(),
        'total_amount' => '50.00',
        'amount_paid' => '10.00',
    ]);
    $allocation = $payment->allocations()->create([
        'invoice_id' => $invoice->getKey(),
        'amount' => '10.00',
    ]);

    $original = JournalEntry::factory()->posted()->create([
        'source_type' => PaymentAllocation::class,
        'source_id' => $allocation->getKey(),
        'description' => 'Existing deposit application',
    ]);
    JournalEntry::factory()->posted()->create([
        'source_type' => JournalEntry::class,
        'source_id' => $original->getKey(),
        'description' => 'Existing reversal',
    ]);

    $before = JournalEntry::query()->count();

    app(CustomerDepositApplicationService::class)->reverseForPayment(
        User::factory()->admin()->create(),
        $payment,
    );

    expect(JournalEntry::query()->count())->toBe($before);
});

it('does not attach purchase coverage when replenishment capacity is already full', function (): void {
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $policy = WarehouseReplenishmentPolicy::factory()->create([
        'warehouse_id' => $warehouse->getKey(),
        'product_variant_id' => $variant->getKey(),
        'min_quantity' => '5.000000',
        'max_quantity' => '10.000000',
        'is_active' => true,
    ]);

    $requirement = ReplenishmentRequirement::query()->create([
        'warehouse_replenishment_policy_id' => $policy->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'product_variant_id' => $variant->getKey(),
        'required_base_quantity' => '10.000000',
        'covered_base_quantity' => '10.000000',
        'fulfilled_base_quantity' => '0.000000',
        'status' => ReplenishmentRequirementStatus::Covered,
        'triggered_at' => now(),
    ]);

    ReplenishmentCoverage::query()->create([
        'replenishment_requirement_id' => $requirement->getKey(),
        'source_type' => ReplenishmentCoverageSourceType::InternalTransfer,
        'source_id' => 999001,
        'covered_base_quantity' => '10.000000',
        'status' => ReplenishmentCoverageStatus::Active,
    ]);

    $order = PurchaseOrder::factory()->accepted()->create();
    $purchaseLine = PurchaseOrderLine::factory()
        ->for($order)
        ->for($variant, 'productVariant')
        ->create([
            'unit_id' => $variant->unit_id,
            'quantity_ordered' => '2.000000',
        ]);
    $purchaseLine->forceFill([
        'base_quantity' => '2.000000',
        'received_base_quantity' => '0.000000',
    ])->saveQuietly();

    $inbound = PurchaseInbound::factory()->create([
        'purchase_order_id' => $order->getKey(),
    ]);
    $inboundLine = PurchaseInboundLine::factory()->create([
        'purchase_inbound_id' => $inbound->getKey(),
        'purchase_order_line_id' => $purchaseLine->getKey(),
    ]);
    PurchaseInboundAllocation::withoutEvents(static fn (): PurchaseInboundAllocation => PurchaseInboundAllocation::factory()->create([
        'purchase_inbound_line_id' => $inboundLine->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'allocated_base_quantity' => '2.000000',
    ]));

    app(PurchaseReplenishmentCoverageService::class)->syncForInboundLine($inboundLine->refresh());

    expect(ReplenishmentCoverage::query()
        ->where('source_type', ReplenishmentCoverageSourceType::PurchaseOrderLine->value)
        ->where('source_id', $purchaseLine->getKey())
        ->exists())->toBeFalse();
});

it('releases stale purchase coverage when an inbound line loses all allocations', function (): void {
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $policy = WarehouseReplenishmentPolicy::factory()->create([
        'warehouse_id' => $warehouse->getKey(),
        'product_variant_id' => $variant->getKey(),
        'min_quantity' => '5.000000',
        'max_quantity' => '10.000000',
        'is_active' => true,
    ]);
    $requirement = ReplenishmentRequirement::query()->create([
        'warehouse_replenishment_policy_id' => $policy->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'product_variant_id' => $variant->getKey(),
        'required_base_quantity' => '5.000000',
        'covered_base_quantity' => '2.000000',
        'fulfilled_base_quantity' => '0.000000',
        'status' => ReplenishmentRequirementStatus::PartiallyCovered,
        'triggered_at' => now(),
    ]);

    $order = PurchaseOrder::factory()->accepted()->create();
    $purchaseLine = PurchaseOrderLine::factory()
        ->for($order)
        ->for($variant, 'productVariant')
        ->create([
            'unit_id' => $variant->unit_id,
            'quantity_ordered' => '2.000000',
        ]);
    $purchaseLine->forceFill([
        'base_quantity' => '2.000000',
        'received_base_quantity' => '0.000000',
    ])->saveQuietly();

    $coverage = ReplenishmentCoverage::query()->create([
        'replenishment_requirement_id' => $requirement->getKey(),
        'source_type' => ReplenishmentCoverageSourceType::PurchaseOrderLine,
        'source_id' => $purchaseLine->getKey(),
        'covered_base_quantity' => '2.000000',
        'status' => ReplenishmentCoverageStatus::Active,
    ]);

    $inbound = PurchaseInbound::factory()->create([
        'purchase_order_id' => $order->getKey(),
    ]);
    $inboundLine = PurchaseInboundLine::factory()->create([
        'purchase_inbound_id' => $inbound->getKey(),
        'purchase_order_line_id' => $purchaseLine->getKey(),
    ]);

    app(PurchaseReplenishmentCoverageService::class)->syncForInboundLine($inboundLine);

    expect($coverage->refresh()->status)->toBe(ReplenishmentCoverageStatus::Released);
});
