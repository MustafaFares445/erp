<?php

declare(strict_types=1);

use App\Enums\MaintenanceStatus;
use App\Enums\SerializedCustodyType;
use App\Enums\WarrantyClaimDecision;
use App\Enums\WarrantyCoverageSource;
use App\Enums\WarrantyEntitlementState;
use App\Enums\WarrantyFailureCategory;
use App\Enums\WarrantyLineCategory;
use App\Enums\WarrantyStatus;
use App\Models\CustomerProfile;
use App\Models\MaintenanceLabourEntry;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\MaintenanceThirdPartyCost;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\ServiceRecordPart;
use App\Models\User;
use App\Models\WarrantyEntitlement;
use App\Services\Support\WarrantyClaimService;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function coverage66Manager(): User
{
    (new SupportPermissionSeeder)->run();

    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    return $manager;
}

function coverage66Diagnosed(User $manager, array $attributes = []): MaintenanceRecord
{
    $record = MaintenanceRecord::factory()->covered()->create([
        'status' => MaintenanceStatus::Open,
        ...$attributes,
    ]);

    return app(WarrantyClaimService::class)->recordDiagnosis($record, [
        'diagnosis_summary' => 'Coverage diagnosis.',
        'root_cause' => 'Coverage root cause.',
        'failure_category' => WarrantyFailureCategory::NormalComponentFailure->value,
    ], $manager);
}

it('suggests warranty lines from consumed parts labour and third-party costs using entitlement coverage flags', function (): void {
    $manager = coverage66Manager();
    $customer = CustomerProfile::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $customer->id,
    ]);
    $record = MaintenanceRecord::factory()->covered()->create([
        'customer_id' => $customer->id,
        'serialized_inventory_unit_id' => $unit->id,
    ]);

    WarrantyEntitlement::factory()->create([
        'serialized_inventory_unit_id' => $unit->id,
        'customer_id' => $customer->id,
        'state' => WarrantyEntitlementState::Active,
        'covers_parts' => true,
        'covers_labour' => false,
        'covers_third_party' => true,
    ]);

    $task = MaintenanceTask::factory()->for($record, 'maintenanceRecord')->create();
    $variant = ProductVariant::factory()->create([
        'name' => 'Coverage replacement part',
        'sku' => 'C66-PART',
        'base_price' => '25.00',
    ]);

    ServiceRecordPart::factory()->for($task, 'maintenanceTask')->create([
        'product_variant_id' => $variant->id,
        'quantity' => '2.000',
    ]);
    ServiceRecordPart::factory()->reversed()->for($task, 'maintenanceTask')->create([
        'product_variant_id' => $variant->id,
        'quantity' => '1.000',
    ]);

    MaintenanceLabourEntry::factory()->create([
        'maintenance_record_id' => $record->id,
        'total_cost_minor' => 12000,
    ]);
    MaintenanceThirdPartyCost::factory()->create([
        'maintenance_record_id' => $record->id,
        'description' => 'External calibration',
        'amount_minor' => 8000,
    ]);

    $lines = app(WarrantyClaimService::class)->suggestedCoverageLines($record->refresh());

    expect($lines)->toHaveCount(3);

    $parts = collect($lines)->firstWhere('category', WarrantyLineCategory::Part->value);
    $labour = collect($lines)->firstWhere('category', WarrantyLineCategory::Labour->value);
    $thirdParty = collect($lines)->firstWhere('category', WarrantyLineCategory::ThirdParty->value);

    expect($parts['description'])->toBe('Coverage replacement part')
        ->and($parts['amount_minor'])->toBe(5000)
        ->and($parts['coverage_percent'])->toBe(100.0)
        ->and($parts['coverage_source'])->toBe(WarrantyCoverageSource::SellerWarranty->value)
        ->and($labour['amount_minor'])->toBe(12000)
        ->and($labour['coverage_percent'])->toBe(0.0)
        ->and($labour['coverage_source'])->toBe(WarrantyCoverageSource::CustomerPaid->value)
        ->and($thirdParty['amount_minor'])->toBe(8000)
        ->and($thirdParty['coverage_percent'])->toBe(100.0)
        ->and($thirdParty['coverage_source'])->toBe(WarrantyCoverageSource::SellerWarranty->value);

    expect($manager)->toBeInstanceOf(User::class);
});

it('covers warranty-decision validation for pending decisions missing explanations and malformed lines', function (): void {
    $manager = coverage66Manager();
    $service = app(WarrantyClaimService::class);
    $record = coverage66Diagnosed($manager);

    expect(fn () => $service->decideCoverage($record, [
        'coverage_decision' => WarrantyClaimDecision::PendingDiagnosis->value,
        'coverage_reason' => 'Still pending.',
    ], $manager))->toThrow(ValidationException::class, 'Choose a final coverage decision');

    expect(fn () => $service->decideCoverage($record->refresh(), [
        'coverage_decision' => WarrantyClaimDecision::Rejected->value,
        'coverage_reason' => 'Not covered.',
        'customer_coverage_explanation' => '   ',
    ], $manager))->toThrow(ValidationException::class, 'Explain the customer responsibility');

    expect(fn () => $service->decideCoverage($record->refresh(), [
        'coverage_decision' => WarrantyClaimDecision::PartiallyCovered->value,
        'coverage_reason' => 'Partial coverage.',
        'customer_coverage_explanation' => 'Customer pays a portion.',
        'coverage_lines' => [[
            'category' => WarrantyLineCategory::Part->value,
            'description' => 'Invalid amount line',
            'amount_minor' => -1,
            'coverage_percent' => 50,
        ]],
    ], $manager))->toThrow(ValidationException::class, 'non-negative amount');
});

it('skips non-array submitted coverage lines while finalizing an otherwise valid decision', function (): void {
    $manager = coverage66Manager();
    $record = coverage66Diagnosed($manager);

    $updated = app(WarrantyClaimService::class)->decideCoverage($record, [
        'coverage_decision' => WarrantyClaimDecision::FullyCovered->value,
        'coverage_reason' => 'Fully covered.',
        'customer_coverage_explanation' => 'No customer charge.',
        'coverage_lines' => ['skip-this-scalar'],
    ], $manager);

    expect($updated->coverage_decision)->toBe(WarrantyClaimDecision::FullyCovered)
        ->and($updated->coverageLines()->count())->toBe(0);
});

it('covers low-level claim source and pending-line persistence branches', function (): void {
    $manager = coverage66Manager();
    $record = MaintenanceRecord::factory()->create([
        'warranty_status' => WarrantyStatus::Unknown,
    ]);
    $service = app(WarrantyClaimService::class);

    $thirdPartySource = new ReflectionMethod(WarrantyClaimService::class, 'thirdPartySource');
    expect($thirdPartySource->invoke($service, WarrantyCoverageSource::ManufacturerWarranty->value))
        ->toBe(WarrantyCoverageSource::ManufacturerWarranty);

    $persist = new ReflectionMethod(WarrantyClaimService::class, 'persistCoverageLine');
    $persist->invoke(
        $service,
        $record,
        [
            'category' => WarrantyLineCategory::Labour->value,
            'description' => 'Pending diagnostic coverage line',
            'amount_minor' => 1000,
        ],
        WarrantyClaimDecision::PendingDiagnosis,
        null,
        $manager,
    );

    $line = $record->coverageLines()->sole();
    expect((float) $line->coverage_percent)->toBe(0.0)
        ->and($line->covered_amount_minor)->toBe(0)
        ->and($line->customer_amount_minor)->toBe(1000)
        ->and($line->coverage_source)->toBe(WarrantyCoverageSource::CustomerPaid);
});
