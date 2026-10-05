<?php

declare(strict_types=1);

use App\Enums\WarrantyEntitlementState;
use App\Enums\WarrantyStatus;
use App\Models\CustomerProfile;
use App\Models\MaintenanceLabourEntry;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\MaintenanceThirdPartyCost;
use App\Models\ProductVariant;
use App\Models\SalesSetting;
use App\Models\SerializedInventoryUnit;
use App\Models\ServiceRecordPart;
use App\Models\User;
use App\Models\WarrantyEntitlement;
use App\Services\Support\WarrantyClaimService;
use App\Services\Support\WarrantyEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
    SalesSetting::factory()->create();
});

it('covers the locked warranty-entitlement race guard', function (): void {
    $customer = CustomerProfile::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create();
    $actor = User::factory()->admin()->create();

    $entitlement = WarrantyEntitlement::factory()->create([
        'serialized_inventory_unit_id' => $unit->id,
        'customer_id' => $customer->id,
        'state' => WarrantyEntitlementState::PendingActivation,
    ]);

    WarrantyEntitlement::query()
        ->whereKey($entitlement->id)
        ->update(['state' => WarrantyEntitlementState::Active->value]);

    expect(fn () => app(WarrantyEntitlementService::class)->activate(
        $entitlement,
        today(),
        $actor,
        'Race coverage guard.',
    ))->toThrow(DomainException::class, 'already been processed');
});

it('covers legacy warranty fallbacks and a missing part variant in suggested coverage', function (): void {
    $record = MaintenanceRecord::factory()->covered()->create([
        'serialized_inventory_unit_id' => null,
    ]);

    $task = MaintenanceTask::factory()->for($record, 'maintenanceRecord')->create();
    $variant = ProductVariant::factory()->create([
        'name' => 'Legacy covered part',
        'sku' => 'LEGACY-COVERAGE-PART',
        'base_price' => '25.00',
    ]);

    ServiceRecordPart::factory()->for($task, 'maintenanceTask')->create([
        'product_variant_id' => $variant->id,
        'quantity' => '2.000000',
    ]);
    ServiceRecordPart::factory()->for($task, 'maintenanceTask')->create([
        'product_variant_id' => ProductVariant::factory()->create()->id,
        'quantity' => '1.000000',
    ]);

    MaintenanceLabourEntry::factory()->create([
        'maintenance_record_id' => $record->id,
        'total_cost_minor' => 6000,
    ]);
    MaintenanceThirdPartyCost::factory()->create([
        'maintenance_record_id' => $record->id,
        'description' => 'Legacy third-party charge',
        'amount_minor' => 4000,
    ]);

    $record->load([
        'customer.user',
        'serviceRecords.parts.productVariant',
        'labourEntries',
        'thirdPartyCosts',
        'serializedInventoryUnit.warrantyEntitlements',
    ]);

    $parts = $record->serviceRecords->firstOrFail()->parts;
    $parts->last()->setRelation('productVariant', null);

    $lines = app(WarrantyClaimService::class)->suggestedCoverageLines($record);

    $part = collect($lines)->firstWhere('category', 'part');
    $labour = collect($lines)->firstWhere('category', 'labour');
    $thirdParty = collect($lines)->firstWhere('category', 'third_party');

    expect($lines)->toHaveCount(3)
        ->and($part['coverage_percent'])->toBe(100.0)
        ->and($labour['coverage_percent'])->toBe(100.0)
        ->and($thirdParty['coverage_percent'])->toBe(0.0);
});
