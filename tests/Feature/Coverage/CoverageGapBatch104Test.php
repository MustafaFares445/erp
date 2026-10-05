<?php

declare(strict_types=1);

use App\Enums\PurchaseRfqStatus;
use App\Enums\WarrantyClaimDecision;
use App\Enums\WarrantyEntitlementState;
use App\Enums\WarrantyLineCategory;
use App\Enums\WarrantyStatus;
use App\Models\MaintenanceCoverageLine;
use App\Models\MaintenanceLabourEntry;
use App\Models\MaintenanceRecord;
use App\Models\ProductVariant;
use App\Models\ProductVariantUnit;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\Unit;
use App\Models\User;
use App\Models\WarrantyEntitlement;
use App\Services\Purchasing\PurchaseRfqService;
use App\Services\Support\MaintenanceBillingService;
use App\Services\Support\WarrantyResolver;
use Carbon\CarbonImmutable;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

function coverage104Catalog(): array
{
    $supplier = Supplier::factory()->create(['is_active' => true]);
    $variant = ProductVariant::factory()->create(['is_active' => true]);
    $unit = Unit::factory()->create();

    ProductVariantUnit::factory()->create([
        'product_variant_id' => $variant->id,
        'unit_id' => $unit->id,
        'is_purchase' => true,
        'is_active' => true,
        'factor_to_base' => '1.000000',
    ]);

    SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->id,
        'product_variant_id' => $variant->id,
        'purchase_cost' => '10.00',
        'currency_code' => 'AED',
        'availability_status' => 'active',
        'is_active' => true,
    ]);

    return [$supplier, $variant, $unit];
}

it('covers ended warranty entitlement resolution with default end reason', function (): void {
    $entitlement = new WarrantyEntitlement;
    $entitlement->forceFill([
        'state' => WarrantyEntitlementState::Ended,
        'starts_on' => '2026-01-01',
        'expires_on' => '2026-12-31',
        'end_reason' => null,
    ]);

    $method = new ReflectionMethod(WarrantyResolver::class, 'fromEntitlement');
    $coverage = $method->invoke(
        app(WarrantyResolver::class),
        $entitlement,
        77,
        CarbonImmutable::parse('2026-06-01'),
    );

    expect($coverage->status)->toBe(WarrantyStatus::Expired)
        ->and($coverage->serializedInventoryUnitId)->toBe(77)
        ->and($coverage->reason)->toBe('The warranty entitlement is no longer active.');
});

it('covers unassessed labour fallback percentage when coverage lines exist but no labour line exists', function (): void {
    $record = MaintenanceRecord::factory()->create([
        'coverage_decision' => WarrantyClaimDecision::PartiallyCovered,
    ]);

    MaintenanceCoverageLine::factory()->create([
        'maintenance_record_id' => $record->id,
        'category' => WarrantyLineCategory::Other,
        'description' => 'Unrelated coverage evidence',
        'amount_minor' => 1000,
        'coverage_percent' => 50,
        'covered_amount_minor' => 500,
        'customer_amount_minor' => 500,
    ]);

    MaintenanceLabourEntry::factory()->create([
        'maintenance_record_id' => $record->id,
        'total_cost_minor' => 6000,
    ]);

    $method = new ReflectionMethod(MaintenanceBillingService::class, 'actualCustomerResponsibilityLines');
    $lines = $method->invoke(app(MaintenanceBillingService::class), $record->refresh());

    $labour = collect($lines)->firstWhere('description', 'Labour');

    expect($labour)->not->toBeNull()
        ->and($labour['unit_price'])->toBe(60.0);
});

it('covers RFQ award status missing-response and close guards', function (): void {
    Gate::before(static fn (): bool => true);
    (new CurrencySeeder)->run();

    $actor = User::factory()->create();
    [$supplier, $variant, $unit] = coverage104Catalog();
    $service = app(PurchaseRfqService::class);

    $rfq = $service->create($actor, [
        'currency_code' => 'AED',
        'closes_at' => now()->addDay()->toDateTimeString(),
    ], [[
        'product_variant_id' => $variant->id,
        'unit_id' => $unit->id,
        'quantity' => 1,
    ]], [$supplier->id]);

    $service->send($actor, $rfq);
    $candidate = $rfq->refresh()->suppliers()->firstOrFail();

    expect(fn () => $service->award($actor, $candidate))
        ->toThrow(DomainException::class, 'responses under evaluation');

    $rfq->forceFill(['status' => PurchaseRfqStatus::PartiallyResponded])->save();

    expect(fn () => $service->award($actor, $candidate))
        ->toThrow(DomainException::class, 'must quote every RFQ line');

    $rfq->forceFill(['status' => PurchaseRfqStatus::Sent])->save();

    expect(fn () => $service->close($actor, $rfq->refresh()))
        ->toThrow(DomainException::class, 'Only an awarded RFQ can be closed');
});
