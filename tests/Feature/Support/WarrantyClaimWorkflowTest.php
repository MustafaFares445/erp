<?php

declare(strict_types=1);

use App\Enums\MaintenanceStatus;
use App\Enums\WarrantyClaimDecision;
use App\Enums\WarrantyCoverageSource;
use App\Enums\WarrantyFailureCategory;
use App\Enums\WarrantyLineCategory;
use App\Enums\WarrantyStatus;
use App\Models\MaintenanceRecord;
use App\Models\User;
use App\Services\Support\WarrantyClaimService;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
});

function warrantyClaimManager(): User
{
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    return $manager;
}

it('moves a diagnosed active-warranty job from diagnosis to fully covered and ready for repair', function (): void {
    $manager = warrantyClaimManager();
    $record = MaintenanceRecord::factory()->covered()->create([
        'status' => MaintenanceStatus::Open,
    ]);
    $service = app(WarrantyClaimService::class);

    $service->recordDiagnosis($record, [
        'diagnosis_summary' => 'Power supply failed during normal use.',
        'root_cause' => 'Internal power supply component failure.',
        'failure_category' => WarrantyFailureCategory::NormalComponentFailure->value,
    ], $manager);

    expect($record->refresh()->status)->toBe(MaintenanceStatus::Diagnosing)
        ->and($record->diagnosed_at)->not->toBeNull()
        ->and($record->coverage_decision)->toBe(WarrantyClaimDecision::PendingDiagnosis);

    $service->decideCoverage($record, [
        'coverage_decision' => WarrantyClaimDecision::FullyCovered->value,
        'coverage_reason' => 'Normal component failure is covered by the active warranty.',
        'customer_coverage_explanation' => 'Your repair is fully covered under warranty.',
        'coverage_lines' => [[
            'category' => WarrantyLineCategory::Part->value,
            'description' => 'Power supply replacement',
            'amount_minor' => 30000,
            'coverage_percent' => 100,
            'coverage_source' => WarrantyCoverageSource::SellerWarranty->value,
        ]],
    ], $manager);

    $record->refresh();
    $summary = $service->coverageSummary($record);

    expect($record->status)->toBe(MaintenanceStatus::ReadyForRepair)
        ->and($record->coverage_decision)->toBe(WarrantyClaimDecision::FullyCovered)
        ->and($record->coverage_source)->toBe(WarrantyCoverageSource::SellerWarranty)
        ->and($summary)->toBe([
            'total_amount_minor' => 30000,
            'covered_amount_minor' => 30000,
            'customer_amount_minor' => 0,
        ]);
});

it('calculates partial warranty coverage and waits for customer approval of the uncovered amount', function (): void {
    $manager = warrantyClaimManager();
    $record = MaintenanceRecord::factory()->covered()->create([
        'status' => MaintenanceStatus::Open,
    ]);
    $service = app(WarrantyClaimService::class);

    $service->recordDiagnosis($record, [
        'diagnosis_summary' => 'Covered part failed, travel remains customer-paid.',
        'root_cause' => 'Normal component failure.',
        'failure_category' => WarrantyFailureCategory::NormalComponentFailure->value,
    ], $manager);

    $service->decideCoverage($record, [
        'coverage_decision' => WarrantyClaimDecision::PartiallyCovered->value,
        'coverage_reason' => 'Part and labour are covered but travel is excluded.',
        'customer_coverage_explanation' => 'Warranty covers the repair; you only pay the excluded travel amount.',
        'coverage_lines' => [
            [
                'category' => WarrantyLineCategory::Part->value,
                'description' => 'Covered component',
                'amount_minor' => 40000,
                'coverage_percent' => 100,
                'coverage_source' => WarrantyCoverageSource::SellerWarranty->value,
            ],
            [
                'category' => WarrantyLineCategory::Travel->value,
                'description' => 'Travel',
                'amount_minor' => 10000,
                'coverage_percent' => 0,
                'coverage_source' => WarrantyCoverageSource::CustomerPaid->value,
            ],
        ],
    ], $manager);

    $record->refresh();

    expect($record->status)->toBe(MaintenanceStatus::AwaitingApproval)
        ->and($record->coverage_decision)->toBe(WarrantyClaimDecision::PartiallyCovered)
        ->and($service->coverageSummary($record))->toBe([
            'total_amount_minor' => 50000,
            'covered_amount_minor' => 40000,
            'customer_amount_minor' => 10000,
        ]);
});

it('rejects seller-warranty coverage when eligibility is expired but allows goodwill as a separate commercial decision', function (): void {
    $manager = warrantyClaimManager();
    $record = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Open,
        'warranty_status' => WarrantyStatus::Expired,
        'warranty_expiry_date' => today()->subMonth(),
    ]);
    $service = app(WarrantyClaimService::class);

    $service->recordDiagnosis($record, [
        'diagnosis_summary' => 'Failure confirmed after warranty expiry.',
        'root_cause' => 'Component wear.',
        'failure_category' => WarrantyFailureCategory::NormalComponentFailure->value,
    ], $manager);

    expect(fn () => $service->decideCoverage($record, [
        'coverage_decision' => WarrantyClaimDecision::FullyCovered->value,
        'coverage_reason' => 'Attempt seller warranty.',
        'customer_coverage_explanation' => 'Attempt seller warranty.',
    ], $manager))->toThrow(ValidationException::class);

    $service->decideCoverage($record->refresh(), [
        'coverage_decision' => WarrantyClaimDecision::Goodwill->value,
        'coverage_reason' => 'Commercial courtesy approved for strategic customer.',
        'customer_coverage_explanation' => 'The company will cover this repair as a commercial courtesy.',
        'coverage_lines' => [[
            'category' => WarrantyLineCategory::Labour->value,
            'description' => 'Repair labour',
            'amount_minor' => 15000,
            'coverage_percent' => 0,
        ]],
    ], $manager);

    expect($record->refresh()->status)->toBe(MaintenanceStatus::ReadyForRepair)
        ->and($record->coverage_decision)->toBe(WarrantyClaimDecision::Goodwill)
        ->and($record->coverage_source)->toBe(WarrantyCoverageSource::Goodwill)
        ->and($service->coverageSummary($record)['customer_amount_minor'])->toBe(0);
});
