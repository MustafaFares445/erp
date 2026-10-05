<?php

declare(strict_types=1);

use App\Enums\WarrantyClaimDecision;
use App\Enums\WarrantyCoverageSource;
use App\Enums\WarrantyLineCategory;
use App\Models\MaintenanceCoverageLine;
use App\Models\MaintenanceLabourEntry;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\MaintenanceThirdPartyCost;
use App\Models\ProductVariant;
use App\Models\SalesSetting;
use App\Models\ServiceRecordPart;
use App\Services\Support\MaintenanceBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rebuilds actual customer responsibility across parts labour third-party and generic frozen coverage rows', function (): void {
    SalesSetting::factory()->create(['default_tax_percent' => '0.00']);

    $record = MaintenanceRecord::factory()->create([
        'coverage_decision' => WarrantyClaimDecision::PartiallyCovered,
    ]);
    $task = MaintenanceTask::factory()->for($record, 'maintenanceRecord')->create();

    $partiallyCoveredVariant = ProductVariant::factory()->create([
        'name' => 'Partially covered part',
        'base_price' => '20.00',
    ]);
    $fullyCoveredVariant = ProductVariant::factory()->create([
        'name' => 'Fully covered part',
        'base_price' => '20.00',
    ]);
    $unassessedVariant = ProductVariant::factory()->create([
        'name' => 'Unassessed part',
        'base_price' => '30.00',
    ]);

    $partialPart = ServiceRecordPart::factory()->for($task, 'maintenanceTask')->create([
        'product_variant_id' => $partiallyCoveredVariant->id,
        'quantity' => '2.000',
    ]);
    $fullPart = ServiceRecordPart::factory()->for($task, 'maintenanceTask')->create([
        'product_variant_id' => $fullyCoveredVariant->id,
        'quantity' => '1.000',
    ]);
    ServiceRecordPart::factory()->for($task, 'maintenanceTask')->create([
        'product_variant_id' => $unassessedVariant->id,
        'quantity' => '1.000',
    ]);
    ServiceRecordPart::factory()->reversed()->for($task, 'maintenanceTask')->create([
        'product_variant_id' => $partiallyCoveredVariant->id,
        'quantity' => '1.000',
    ]);

    MaintenanceCoverageLine::factory()->create([
        'maintenance_record_id' => $record->id,
        'category' => WarrantyLineCategory::Part,
        'description' => 'Partial part coverage',
        'source_type' => ServiceRecordPart::class,
        'source_id' => $partialPart->id,
        'amount_minor' => 4000,
        'coverage_percent' => 50,
        'covered_amount_minor' => 2000,
        'customer_amount_minor' => 2000,
        'coverage_source' => WarrantyCoverageSource::SellerWarranty,
    ]);
    MaintenanceCoverageLine::factory()->create([
        'maintenance_record_id' => $record->id,
        'category' => WarrantyLineCategory::Part,
        'description' => 'Full part coverage',
        'source_type' => ServiceRecordPart::class,
        'source_id' => $fullPart->id,
        'amount_minor' => 2000,
        'coverage_percent' => 100,
        'covered_amount_minor' => 2000,
        'customer_amount_minor' => 0,
        'coverage_source' => WarrantyCoverageSource::SellerWarranty,
    ]);

    MaintenanceLabourEntry::factory()->create([
        'maintenance_record_id' => $record->id,
        'total_cost_minor' => 10000,
    ]);
    MaintenanceCoverageLine::factory()->create([
        'maintenance_record_id' => $record->id,
        'category' => WarrantyLineCategory::Labour,
        'description' => 'Labour coverage',
        'source_type' => 'maintenance_labour',
        'source_id' => null,
        'amount_minor' => 10000,
        'coverage_percent' => 25,
        'covered_amount_minor' => 2500,
        'customer_amount_minor' => 7500,
        'coverage_source' => WarrantyCoverageSource::SellerWarranty,
    ]);

    $cost = MaintenanceThirdPartyCost::factory()->create([
        'maintenance_record_id' => $record->id,
        'description' => 'External vendor',
        'amount_minor' => 4000,
    ]);
    MaintenanceCoverageLine::factory()->create([
        'maintenance_record_id' => $record->id,
        'category' => WarrantyLineCategory::ThirdParty,
        'description' => 'Third party coverage',
        'source_type' => MaintenanceThirdPartyCost::class,
        'source_id' => $cost->id,
        'amount_minor' => 4000,
        'coverage_percent' => 50,
        'covered_amount_minor' => 2000,
        'customer_amount_minor' => 2000,
        'coverage_source' => WarrantyCoverageSource::SellerWarranty,
    ]);

    MaintenanceCoverageLine::factory()->create([
        'maintenance_record_id' => $record->id,
        'category' => WarrantyLineCategory::Other,
        'description' => 'Generic customer share',
        'source_type' => null,
        'source_id' => null,
        'amount_minor' => 1500,
        'coverage_percent' => 0,
        'covered_amount_minor' => 0,
        'customer_amount_minor' => 1500,
        'coverage_source' => WarrantyCoverageSource::CustomerPaid,
    ]);
    MaintenanceCoverageLine::factory()->create([
        'maintenance_record_id' => $record->id,
        'category' => WarrantyLineCategory::Other,
        'description' => 'Ignored sourced row',
        'source_type' => 'other-source',
        'source_id' => 999,
        'amount_minor' => 1000,
        'coverage_percent' => 0,
        'covered_amount_minor' => 0,
        'customer_amount_minor' => 1000,
        'coverage_source' => WarrantyCoverageSource::CustomerPaid,
    ]);
    MaintenanceCoverageLine::factory()->create([
        'maintenance_record_id' => $record->id,
        'category' => WarrantyLineCategory::Other,
        'description' => 'Ignored zero customer row',
        'source_type' => null,
        'source_id' => null,
        'amount_minor' => 1000,
        'coverage_percent' => 100,
        'covered_amount_minor' => 1000,
        'customer_amount_minor' => 0,
        'coverage_source' => WarrantyCoverageSource::SellerWarranty,
    ]);

    $method = new ReflectionMethod(MaintenanceBillingService::class, 'actualCustomerResponsibilityLines');
    $lines = $method->invoke(app(MaintenanceBillingService::class), $record->refresh());

    expect($lines)->toHaveCount(5)
        ->and(collect($lines)->contains(fn (array $line): bool => ($line['product_variant_id'] ?? null) === $partiallyCoveredVariant->id
            && ($line['unit_price'] ?? null) === 10.0
            && ($line['quantity'] ?? null) === 2.0
        ))->toBeTrue()
        ->and(collect($lines)->contains(fn (array $line): bool => ($line['product_variant_id'] ?? null) === $unassessedVariant->id
            && ($line['unit_price'] ?? null) === 30.0
        ))->toBeTrue()
        ->and(collect($lines)->contains(fn (array $line): bool => ($line['description'] ?? null) === 'Labour'
            && ($line['unit_price'] ?? null) === 75.0
        ))->toBeTrue()
        ->and(collect($lines)->contains(fn (array $line): bool => ($line['description'] ?? null) === 'External vendor'
            && ($line['unit_price'] ?? null) === 20.0
        ))->toBeTrue()
        ->and(collect($lines)->contains(fn (array $line): bool => ($line['description'] ?? null) === 'Generic customer share'
            && ($line['unit_price'] ?? null) === 15.0
        ))->toBeTrue();
});

it('returns no actual or initial customer responsibility for fully covered decisions without coverage rows', function (): void {
    SalesSetting::factory()->create(['default_tax_percent' => '0.00']);

    $record = MaintenanceRecord::factory()->create([
        'coverage_decision' => WarrantyClaimDecision::FullyCovered,
    ]);
    $service = app(MaintenanceBillingService::class);

    $actual = new ReflectionMethod(MaintenanceBillingService::class, 'actualCustomerResponsibilityLines');
    $initial = new ReflectionMethod(MaintenanceBillingService::class, 'customerResponsibilityLines');

    expect($actual->invoke($service, $record))->toBe([])
        ->and($initial->invoke($service, $record))->toBe([]);
});

it('builds standalone third-party billing lines and skips zero-value costs', function (): void {
    SalesSetting::factory()->create(['default_tax_percent' => '0.00']);

    $record = MaintenanceRecord::factory()->create();
    MaintenanceThirdPartyCost::factory()->create([
        'maintenance_record_id' => $record->id,
        'description' => 'Positive vendor cost',
        'amount_minor' => 2500,
    ]);
    MaintenanceThirdPartyCost::factory()->create([
        'maintenance_record_id' => $record->id,
        'description' => 'Zero vendor cost',
        'amount_minor' => 0,
    ]);

    $method = new ReflectionMethod(MaintenanceBillingService::class, 'thirdPartyLines');
    $lines = $method->invoke(app(MaintenanceBillingService::class), $record);

    expect($lines)->toBe([[
        'description' => 'Positive vendor cost',
        'quantity' => 1,
        'unit_price' => 25.0,
        'tax_amount' => 0.0,
    ]]);
});
