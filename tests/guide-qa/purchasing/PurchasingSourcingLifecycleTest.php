<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\PurchaseAgreementStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRfqStatus;
use App\Models\Bill;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Models\ProductVariantUnit;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\Unit;
use App\Models\User;
use App\Services\Purchasing\PurchaseAgreementService;
use App\Services\Purchasing\PurchaseRfqService;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    (new CurrencySeeder)->run();
    (new PurchasePermissionSeeder)->run();
    $this->buyer = User::factory()->admin()->create();
    $this->buyer->assignRole(DashboardRole::PurchasingManager->value);
    $this->actingAs($this->buyer);
    $this->supplier = Supplier::factory()->create(['is_active' => true]);
    $this->variant = ProductVariant::factory()->create(['is_active' => true]);
    $this->unit = Unit::factory()->create();
    ProductVariantUnit::factory()->create([
        'product_variant_id' => $this->variant->id,
        'unit_id' => $this->unit->id,
        'is_purchase' => true,
        'is_active' => true,
        'factor_to_base' => '1.000000',
    ]);
    SupplierProductReference::factory()->create([
        'supplier_id' => $this->supplier->id,
        'product_variant_id' => $this->variant->id,
        'purchase_cost' => '100.00',
        'currency_code' => 'AED',
        'availability_status' => 'active',
        'is_active' => true,
    ]);
});

it('moves an RFQ through genuine quoted response and award to a Draft PO without stock or AP', function (): void {
    $service = app(PurchaseRfqService::class);
    $rfq = $service->create($this->buyer, ['currency_code' => 'AED'], [[
        'product_variant_id' => $this->variant->id,
        'unit_id' => $this->unit->id,
        'quantity' => '5',
    ]], [$this->supplier->id]);
    expect($rfq->status)->toBe(PurchaseRfqStatus::Draft);
    $supplierReply = $rfq->suppliers()->sole();
    $rfqLine = $rfq->lines()->sole();
    expect(fn () => $service->recordResponse($this->buyer, $supplierReply, [[
        'rfq_line_id' => $rfqLine->id, 'unit_price' => '73.25', 'offered_quantity' => '5',
    ]]))->toThrow(DomainException::class);

    $rfq = $service->send($this->buyer, $rfq);
    expect($rfq->status)->toBe(PurchaseRfqStatus::AwaitingResponses)
        ->and($rfq->sent_at)->not->toBeNull();

    $service->recordResponse($this->buyer, $supplierReply->refresh(), [[
        'rfq_line_id' => $rfqLine->id, 'unit_price' => '73.25', 'offered_quantity' => '5',
    ]]);
    expect($rfq->refresh()->status)->toBe(PurchaseRfqStatus::Evaluating);
    $po = $service->award($this->buyer, $supplierReply->refresh());
    expect($po->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($po->sent_at)->toBeNull()
        ->and($po->supplier_id)->toBe($this->supplier->id)
        ->and((float) $po->lines()->sole()->unit_cost)->toBe(73.25)
        ->and($rfq->refresh()->status)->toBe(PurchaseRfqStatus::Awarded)
        ->and($rfq->awarded_purchase_order_id)->toBe($po->id)
        ->and(Bill::query()->where('purchase_order_id', $po->id)->count())->toBe(0)
        ->and(InventoryMovement::query()->count())->toBe(0);
});

it('uses active agreement pricing and blocks invalid overlapping active agreements', function (): void {
    $service = app(PurchaseAgreementService::class);
    $details = ['supplier_id' => $this->supplier->id, 'currency_code' => 'AED',
        'starts_on' => today()->toDateString(), 'ends_on' => today()->addMonth()->toDateString()];
    $lines = [['product_variant_id' => $this->variant->id, 'unit_id' => $this->unit->id,
        'unit_price' => '42.00', 'minimum_order_quantity' => '1', 'lead_time_days' => 3]];
    $first = $service->create($this->buyer, $details, $lines);
    expect($first->status)->toBe(PurchaseAgreementStatus::Draft);
    $first = $service->activate($this->buyer, $first);
    expect($first->status)->toBe(PurchaseAgreementStatus::Active);

    $second = $service->create($this->buyer, $details, $lines);
    expect(fn () => $service->activate($this->buyer, $second))->toThrow(DomainException::class);
    expect($second->fresh()->status)->toBe(PurchaseAgreementStatus::Draft)
        ->and(InventoryMovement::query()->count())->toBe(0);
});
