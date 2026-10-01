<?php

declare(strict_types=1);

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceStatus;
use App\Enums\WarrantyClaimDecision;
use App\Enums\WarrantyCoverageSource;
use App\Enums\WarrantyFailureCategory;
use App\Enums\WarrantyLineCategory;
use App\Enums\WarrantyStatus;
use App\Models\MaintenanceRecord;
use App\Models\SalesSetting;
use App\Models\User;
use App\Services\Support\MaintenanceBillingService;
use App\Services\Support\WarrantyClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
    SalesSetting::factory()->create([
        'default_tax_percent' => '10.00',
        'default_quotation_validity_days' => 30,
    ]);
});

function warrantyClaimRecord(): MaintenanceRecord
{
    return MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Open,
        'billing_type' => MaintenanceBillingType::Unbilled,
        'warranty_status' => WarrantyStatus::Covered,
        'warranty_expiry_date' => today()->addYear(),
    ]);
}

function diagnoseWarrantyClaim(MaintenanceRecord $record, User $actor): MaintenanceRecord
{
    return app(WarrantyClaimService::class)->recordDiagnosis($record, [
        'diagnosis_summary' => 'Power module fails under normal operating load.',
        'root_cause' => 'Internal power module component failure.',
        'failure_category' => WarrantyFailureCategory::NormalComponentFailure->value,
    ], $actor);
}
it('separates diagnosis from warranty eligibility and moves the job into coverage assessment', function (): void {
    $record = warrantyClaimRecord();
    $actor = User::factory()->create();

    $updated = diagnoseWarrantyClaim($record, $actor);

    expect($updated->status)->toBe(MaintenanceStatus::Diagnosing)
        ->and($updated->warranty_status)->toBe(WarrantyStatus::Covered)
        ->and($updated->coverage_decision)->toBe(WarrantyClaimDecision::PendingDiagnosis)
        ->and($updated->diagnosis_summary)->toContain('Power module')
        ->and($updated->diagnosed_at)->not->toBeNull();
});

it('records partial line coverage and quotes only the customer responsibility before repair', function (): void {
    $record = warrantyClaimRecord();
    $actor = User::factory()->create();
    $record = diagnoseWarrantyClaim($record, $actor);

    $record = app(WarrantyClaimService::class)->decideCoverage($record, [
        'coverage_decision' => WarrantyClaimDecision::PartiallyCovered->value,
        'coverage_reason' => 'Parts are covered, travel is excluded by policy.',
        'customer_coverage_explanation' => 'Warranty covers the repair part. Travel remains customer-paid.',
        'coverage_lines' => [
            [
                'category' => WarrantyLineCategory::Part->value,
                'description' => 'Power module',
                'amount_minor' => 30000,
                'coverage_percent' => 100,
                'coverage_source' => WarrantyCoverageSource::SellerWarranty->value,
            ],
            [
                'category' => WarrantyLineCategory::Travel->value,
                'description' => 'On-site travel',
                'amount_minor' => 7500,
                'coverage_percent' => 0,
                'coverage_source' => WarrantyCoverageSource::CustomerPaid->value,
            ],
        ],
    ], $actor);

    $summary = app(WarrantyClaimService::class)->coverageSummary($record);

    expect($record->status)->toBe(MaintenanceStatus::AwaitingApproval)
        ->and($record->coverage_decision)->toBe(WarrantyClaimDecision::PartiallyCovered)
        ->and($summary)->toBe([
            'total_amount_minor' => 37500,
            'covered_amount_minor' => 30000,
            'customer_amount_minor' => 7500,
        ]);

    $quotation = app(MaintenanceBillingService::class)->createQuotation($record, $actor);
    $quotation->load('lines');

    expect($record->refresh()->billing_type)->toBe(MaintenanceBillingType::Quoted)
        ->and($quotation->lines)->toHaveCount(1)
        ->and($quotation->lines->first()?->description)->toBe('On-site travel')
        ->and((float) $quotation->lines->first()?->unit_price)->toBe(75.0)
        ->and((float) $quotation->subtotal)->toBe(75.0);
});
it('moves fully covered claims directly to ready for repair with zero customer responsibility', function (): void {
    $record = warrantyClaimRecord();
    $actor = User::factory()->create();
    $record = diagnoseWarrantyClaim($record, $actor);

    $record = app(WarrantyClaimService::class)->decideCoverage($record, [
        'coverage_decision' => WarrantyClaimDecision::FullyCovered->value,
        'coverage_reason' => 'Covered component failure during active warranty.',
        'customer_coverage_explanation' => 'Repair is approved under warranty at no customer charge.',
        'coverage_lines' => [
            [
                'category' => WarrantyLineCategory::Part->value,
                'description' => 'Power module',
                'amount_minor' => 30000,
                'coverage_percent' => 100,
                'coverage_source' => WarrantyCoverageSource::SellerWarranty->value,
            ],
        ],
    ], $actor);

    $summary = app(WarrantyClaimService::class)->coverageSummary($record);

    expect($record->status)->toBe(MaintenanceStatus::ReadyForRepair)
        ->and($record->coverage_decision)->toBe(WarrantyClaimDecision::FullyCovered)
        ->and($record->coverage_source)->toBe(WarrantyCoverageSource::SellerWarranty)
        ->and($summary['customer_amount_minor'])->toBe(0);
});

it('rejects seller-warranty coverage when the warranty eligibility is not active', function (): void {
    $record = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Open,
        'warranty_status' => WarrantyStatus::Expired,
        'warranty_expiry_date' => today()->subDay(),
    ]);
    $actor = User::factory()->create();
    $record = diagnoseWarrantyClaim($record, $actor);

    expect(fn () => app(WarrantyClaimService::class)->decideCoverage($record, [
        'coverage_decision' => WarrantyClaimDecision::FullyCovered->value,
        'coverage_reason' => 'Attempted expired seller warranty.',
        'customer_coverage_explanation' => 'Should not save.',
    ], $actor))->toThrow(ValidationException::class, 'Seller warranty coverage requires an active warranty entitlement');
});
