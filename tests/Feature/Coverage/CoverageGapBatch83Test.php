<?php

declare(strict_types=1);

use App\Enums\MaintenanceStatus;
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
use App\Models\SalesSetting;
use App\Models\SerializedInventoryUnit;
use App\Models\ServiceRecordPart;
use App\Models\User;
use App\Models\WarrantyEntitlement;
use App\Services\Support\WarrantyClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
    SalesSetting::factory()->create();
});

function coverage83Method(string $method): ReflectionMethod
{
    return new ReflectionMethod(WarrantyClaimService::class, $method);
}

it('covers suggested warranty lines for parts labour and third-party actuals', function (): void {
    $customer = CustomerProfile::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create();
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

    $task = MaintenanceTask::factory()->create(['maintenance_record_id' => $record->id]);
    $variant = ProductVariant::factory()->create([
        'name' => 'Coverage 83 Part',
        'base_price' => '25.00',
    ]);

    ServiceRecordPart::factory()->for($task, 'maintenanceTask')->create([
        'product_variant_id' => $variant->id,
        'quantity' => '2.000000',
    ]);
    ServiceRecordPart::factory()->for($task, 'maintenanceTask')->reversed()->create([
        'product_variant_id' => ProductVariant::factory()->create()->id,
    ]);

    MaintenanceLabourEntry::factory()->create([
        'maintenance_record_id' => $record->id,
        'total_cost_minor' => 12000,
    ]);
    MaintenanceThirdPartyCost::factory()->create([
        'maintenance_record_id' => $record->id,
        'description' => 'External calibration',
        'amount_minor' => 9000,
    ]);

    $lines = app(WarrantyClaimService::class)->suggestedCoverageLines($record->refresh());

    expect($lines)->toHaveCount(3);

    $part = collect($lines)->firstWhere('category', WarrantyLineCategory::Part->value);
    $labour = collect($lines)->firstWhere('category', WarrantyLineCategory::Labour->value);
    $thirdParty = collect($lines)->firstWhere('category', WarrantyLineCategory::ThirdParty->value);

    expect($part['coverage_percent'])->toBe(100.0)
        ->and($part['coverage_source'])->toBe(WarrantyCoverageSource::SellerWarranty->value)
        ->and($labour['coverage_percent'])->toBe(0.0)
        ->and($labour['coverage_source'])->toBe(WarrantyCoverageSource::CustomerPaid->value)
        ->and($thirdParty['coverage_percent'])->toBe(100.0)
        ->and($thirdParty['coverage_source'])->toBe(WarrantyCoverageSource::SellerWarranty->value);
});

it('covers warranty helper validation source and normalization branches', function (): void {
    $service = app(WarrantyClaimService::class);

    expect(fn () => coverage83Method('claimDecision')->invoke($service, 'not-valid'))
        ->toThrow(ValidationException::class, 'valid coverage decision')
        ->and(fn () => coverage83Method('failureCategory')->invoke($service, 'not-valid'))
        ->toThrow(ValidationException::class, 'valid failure category')
        ->and(fn () => coverage83Method('coveragePercent')->invoke($service, null))
        ->toThrow(ValidationException::class, 'Coverage percentage is required')
        ->and(fn () => coverage83Method('coveragePercent')->invoke($service, 101))
        ->toThrow(ValidationException::class, 'between 0 and 100')
        ->and(coverage83Method('coveragePercent')->invoke($service, '12.345'))->toBe(12.35);

    expect(fn () => coverage83Method('thirdPartySource')->invoke($service, WarrantyCoverageSource::SellerWarranty->value))
        ->toThrow(ValidationException::class, 'manufacturer or supplier warranty')
        ->and(coverage83Method('thirdPartySource')->invoke($service, WarrantyCoverageSource::ManufacturerWarranty->value))
        ->toBe(WarrantyCoverageSource::ManufacturerWarranty)
        ->and(coverage83Method('thirdPartySource')->invoke($service, WarrantyCoverageSource::SupplierWarranty->value))
        ->toBe(WarrantyCoverageSource::SupplierWarranty);

    expect(coverage83Method('lineCoverageSource')->invoke($service, null, 0.0, null))
        ->toBe(WarrantyCoverageSource::CustomerPaid)
        ->and(coverage83Method('lineCoverageSource')->invoke(
            $service,
            WarrantyCoverageSource::ManufacturerWarranty->value,
            50.0,
            null,
        ))->toBe(WarrantyCoverageSource::ManufacturerWarranty)
        ->and(coverage83Method('lineCoverageSource')->invoke(
            $service,
            null,
            50.0,
            WarrantyCoverageSource::Goodwill,
        ))->toBe(WarrantyCoverageSource::Goodwill)
        ->and(coverage83Method('lineCoverageSource')->invoke($service, null, 50.0, null))
        ->toBe(WarrantyCoverageSource::SellerWarranty);

    expect(coverage83Method('stringKeyedData')->invoke($service, [
        'category' => 'part',
        0 => 'ignored',
        'description' => 'kept',
    ]))->toBe([
        'category' => 'part',
        'description' => 'kept',
    ])->and(coverage83Method('nullableText')->invoke($service, 123))->toBeNull()
        ->and(coverage83Method('nullableText')->invoke($service, '   '))->toBeNull()
        ->and(coverage83Method('nullableText')->invoke($service, ' text '))->toBe('text')
        ->and(coverage83Method('intValue')->invoke(null, '42'))->toBe(42)
        ->and(coverage83Method('intValue')->invoke(null, 'not-number'))->toBe(0);

    expect(fn () => coverage83Method('requiredText')->invoke($service, ' ', 'field', 'Required'))
        ->toThrow(ValidationException::class, 'Required');
});

it('covers persisted coverage-line decision branches and malformed coverage input', function (): void {
    $service = app(WarrantyClaimService::class);
    $actor = User::factory()->create();
    $record = MaintenanceRecord::factory()->covered()->create([
        'status' => MaintenanceStatus::Diagnosing,
        'diagnosed_at' => now(),
    ]);

    $persist = coverage83Method('persistCoverageLine');

    expect(fn () => $persist->invoke(
        $service,
        $record,
        ['category' => 'bad', 'description' => 'Bad', 'amount_minor' => 1],
        WarrantyClaimDecision::Rejected,
        WarrantyCoverageSource::CustomerPaid,
        $actor,
    ))->toThrow(ValidationException::class, 'valid category');

    expect(fn () => $persist->invoke(
        $service,
        $record,
        ['category' => WarrantyLineCategory::Part->value, 'description' => '', 'amount_minor' => 1],
        WarrantyClaimDecision::Rejected,
        WarrantyCoverageSource::CustomerPaid,
        $actor,
    ))->toThrow(ValidationException::class, 'requires a description');

    expect(fn () => $persist->invoke(
        $service,
        $record,
        ['category' => WarrantyLineCategory::Part->value, 'description' => 'Bad amount', 'amount_minor' => -1],
        WarrantyClaimDecision::Rejected,
        WarrantyCoverageSource::CustomerPaid,
        $actor,
    ))->toThrow(ValidationException::class, 'non-negative amount');

    foreach ([
        [WarrantyClaimDecision::FullyCovered, WarrantyCoverageSource::SellerWarranty, 100.0],
        [WarrantyClaimDecision::Rejected, WarrantyCoverageSource::CustomerPaid, 0.0],
        [WarrantyClaimDecision::Goodwill, WarrantyCoverageSource::Goodwill, 100.0],
        [WarrantyClaimDecision::ServiceContract, WarrantyCoverageSource::ServiceContract, 100.0],
        [WarrantyClaimDecision::ThirdPartyWarranty, WarrantyCoverageSource::SupplierWarranty, 100.0],
    ] as $index => [$decision, $source, $expectedPercent]) {
        $persist->invoke(
            $service,
            $record,
            [
                'category' => WarrantyLineCategory::Labour->value,
                'description' => 'Coverage '.$index,
                'amount_minor' => 1000 + $index,
                'notes' => ' note ',
            ],
            $decision,
            $source,
            $actor,
        );

        $line = $record->coverageLines()->latest('id')->firstOrFail();
        expect((float) $line->coverage_percent)->toBe($expectedPercent)
            ->and($line->coverage_source)->toBe($source);
    }

    $persist->invoke(
        $service,
        $record,
        [
            'category' => WarrantyLineCategory::Travel->value,
            'description' => 'Partial',
            'amount_minor' => 10000,
            'coverage_percent' => 25,
            'coverage_source' => WarrantyCoverageSource::ManufacturerWarranty->value,
        ],
        WarrantyClaimDecision::PartiallyCovered,
        WarrantyCoverageSource::SellerWarranty,
        $actor,
    );

    $partial = $record->coverageLines()->latest('id')->firstOrFail();
    expect((float) $partial->coverage_percent)->toBe(25.0)
        ->and($partial->covered_amount_minor)->toBe(2500)
        ->and($partial->customer_amount_minor)->toBe(7500)
        ->and($partial->coverage_source)->toBe(WarrantyCoverageSource::ManufacturerWarranty);

    expect(fn () => $service->decideCoverage($record->refresh(), [
        'coverage_decision' => WarrantyClaimDecision::PendingDiagnosis->value,
        'coverage_reason' => 'Not final',
    ], $actor))->toThrow(ValidationException::class, 'final coverage decision');

    expect(fn () => $service->decideCoverage($record->refresh(), [
        'coverage_decision' => WarrantyClaimDecision::PartiallyCovered->value,
        'coverage_reason' => 'Partial',
    ], $actor))->toThrow(ValidationException::class, 'Explain the customer responsibility');

    $undiagnosed = MaintenanceRecord::factory()->covered()->create([
        'status' => MaintenanceStatus::Open,
        'diagnosed_at' => null,
    ]);

    expect(fn () => $service->decideCoverage($undiagnosed, [
        'coverage_decision' => WarrantyClaimDecision::FullyCovered->value,
        'coverage_reason' => 'Covered',
        'customer_coverage_explanation' => 'Covered',
    ], $actor))->toThrow(ValidationException::class, 'Record the diagnosis');

    $expired = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Diagnosing,
        'diagnosed_at' => now(),
        'warranty_status' => WarrantyStatus::Expired,
    ]);

    expect(fn () => $service->decideCoverage($expired, [
        'coverage_decision' => WarrantyClaimDecision::FullyCovered->value,
        'coverage_reason' => 'Covered',
        'customer_coverage_explanation' => 'Covered',
    ], $actor))->toThrow(ValidationException::class, 'active warranty entitlement');

    $goodwill = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Diagnosing,
        'diagnosed_at' => now(),
        'warranty_status' => WarrantyStatus::Expired,
    ]);

    $updated = $service->decideCoverage($goodwill, [
        'coverage_decision' => WarrantyClaimDecision::Goodwill->value,
        'coverage_reason' => 'Courtesy',
        'coverage_lines' => [
            'ignore-me',
            [
                'category' => WarrantyLineCategory::Labour->value,
                'description' => 'Courtesy labour',
                'amount_minor' => 5000,
            ],
        ],
    ], $actor);

    expect($updated->status)->toBe(MaintenanceStatus::ReadyForRepair)
        ->and($updated->coverageLines)->toHaveCount(1);
});
