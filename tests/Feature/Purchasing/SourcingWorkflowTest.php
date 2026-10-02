<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\PurchaseAgreementStatus;
use App\Enums\PurchaseRfqStatus;
use App\Models\ProductVariant;
use App\Models\ProductVariantUnit;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\Unit;
use App\Models\User;
use App\Services\Purchasing\PurchaseAgreementService;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Purchasing\PurchaseRfqService;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function sourcingActor(): User
{
    (new CurrencySeeder)->run();
    (new PurchasePermissionSeeder)->run();

    $actor = User::factory()->admin()->create();
    $actor->assignRole(DashboardRole::PurchasingManager->value);

    return $actor;
}

/** @return array{0:Supplier,1:ProductVariant,2:Unit} */
function sourcingCatalog(string $referenceCost = '100.00'): array
{
    $supplier = Supplier::factory()->create(['is_active' => true]);
    $variant = ProductVariant::factory()->create(['is_active' => true]);
    $unit = Unit::factory()->create();

    ProductVariantUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $unit->getKey(),
        'is_purchase' => true,
        'is_active' => true,
        'factor_to_base' => '1.000000',
    ]);

    SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'purchase_cost' => $referenceCost,
        'currency_code' => 'AED',
        'availability_status' => 'active',
        'is_active' => true,
    ]);

    return [$supplier, $variant, $unit];
}

it('awards a fully quoted rfq into the canonical purchase order service', function (): void {
    $actor = sourcingActor();
    [$supplier, $variant, $unit] = sourcingCatalog();
    $service = app(PurchaseRfqService::class);

    $rfq = $service->create($actor, [
        'currency_code' => 'AED',
        'needed_by' => now()->addWeek()->toDateString(),
    ], [[
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity' => '5',
    ]], [$supplier->getKey()]);

    $service->send($actor, $rfq);
    $candidate = $rfq->refresh()->suppliers()->firstOrFail();
    $line = $rfq->lines()->firstOrFail();

    $service->recordResponse($actor, $candidate, [[
        'rfq_line_id' => $line->getKey(),
        'unit_price' => '73.25',
        'offered_quantity' => '5',
        'lead_time_days' => 4,
    ]]);

    $order = $service->award($actor, $candidate->refresh());

    $awarded = $rfq->refresh();

    expect($awarded->status)->toBe(PurchaseRfqStatus::Awarded)
        ->and($awarded->awarded_purchase_order_id)->toBe($order->getKey())
        ->and($order->supplier_id)->toBe($supplier->getKey())
        ->and($order->lines()->count())->toBe(1)
        ->and((float) $order->lines()->firstOrFail()->unit_cost)->toBe(73.25);
});

it('uses an active purchase agreement before supplier reference while preserving manual override', function (): void {
    $actor = sourcingActor();
    [$supplier, $variant, $unit] = sourcingCatalog('100.00');

    $agreement = app(PurchaseAgreementService::class)->create($actor, [
        'supplier_id' => $supplier->getKey(),
        'currency_code' => 'AED',
        'starts_on' => today()->toDateString(),
    ], [[
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $unit->getKey(),
        'unit_price' => '42.00',
        'minimum_order_quantity' => '1',
        'lead_time_days' => 3,
    ]]);

    app(PurchaseAgreementService::class)->activate($actor, $agreement);
    $purchaseOrders = app(PurchaseOrderService::class);

    $agreementOrder = $purchaseOrders->createDraft($actor, [
        'supplier_id' => $supplier->getKey(),
        'currency_code' => 'AED',
        'ordered_at' => today()->toDateString(),
    ]);

    $agreementLine = $purchaseOrders->addLine($actor, $agreementOrder, [
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity_ordered' => '2',
    ]);

    $manualOrder = $purchaseOrders->createDraft($actor, [
        'supplier_id' => $supplier->getKey(),
        'currency_code' => 'AED',
        'ordered_at' => today()->toDateString(),
    ]);
    $manualLine = $purchaseOrders->addLine($actor, $manualOrder, [
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity_ordered' => '2',
        'unit_cost' => '55.00',
    ]);

    expect((float) $agreementLine->unit_cost)->toBe(42.0)
        ->and((float) $manualLine->unit_cost)->toBe(55.0)
        ->and((float) $agreement->refresh()->lines()->firstOrFail()->unit_price)->toBe(42.0);
});

it('enforces rfq response, award, close and cancel transition boundaries', function (): void {
    $actor = sourcingActor();
    [$supplier, $variant, $unit] = sourcingCatalog();
    $service = app(PurchaseRfqService::class);

    $rfq = $service->create($actor, ['currency_code' => 'AED'], [[
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity' => '2',
    ]], [$supplier->getKey()]);
    $candidate = $rfq->suppliers()->firstOrFail();
    $line = $rfq->lines()->firstOrFail();

    expect(fn () => $service->recordResponse($actor, $candidate, [[
        'rfq_line_id' => $line->getKey(),
        'unit_price' => '10.00',
        'offered_quantity' => '2',
    ]]))->toThrow(DomainException::class, 'not open for supplier responses');

    $service->send($actor, $rfq);
    $service->recordResponse($actor, $candidate->refresh(), [[
        'rfq_line_id' => $line->getKey(),
        'unit_price' => '10.00',
        'offered_quantity' => '2',
    ]]);
    $service->award($actor, $candidate->refresh());

    $closed = $service->close($actor, $rfq->refresh());
    expect($closed->status)->toBe(PurchaseRfqStatus::Closed)
        ->and($closed->status->isTerminal())->toBeTrue()
        ->and($closed->closed_at)->not->toBeNull();

    expect(fn () => $service->cancel($actor, $closed))->toThrow(DomainException::class, 'can no longer be cancelled');
});

it('rejects overlapping active agreement pricing for the same supplier variant unit and currency', function (): void {
    $actor = sourcingActor();
    [$supplier, $variant, $unit] = sourcingCatalog();
    $service = app(PurchaseAgreementService::class);
    $line = [[
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $unit->getKey(),
        'unit_price' => '40.00',
    ]];

    $first = $service->create($actor, [
        'supplier_id' => $supplier->getKey(),
        'currency_code' => 'AED',
        'starts_on' => today()->toDateString(),
        'ends_on' => today()->addMonth()->toDateString(),
    ], $line);
    $first = $service->activate($actor, $first);

    $second = $service->create($actor, [
        'supplier_id' => $supplier->getKey(),
        'currency_code' => 'AED',
        'starts_on' => today()->addDays(10)->toDateString(),
        'ends_on' => today()->addMonths(2)->toDateString(),
    ], $line);

    expect(fn () => $service->activate($actor, $second))
        ->toThrow(DomainException::class, 'overlapping active purchase agreement');

    $service->cancel($actor, $first);
    $second = $service->activate($actor, $second);

    expect($second->status)->toBe(PurchaseAgreementStatus::Active);
});
