<?php

declare(strict_types=1);

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceStatus;
use App\Enums\QuotationStatus;
use App\Enums\WarrantyClaimDecision;
use App\Enums\WarrantyLineCategory;
use App\Models\MaintenanceCoverageLine;
use App\Models\MaintenanceLabourEntry;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\MaintenanceThirdPartyCost;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\SalesSetting;
use App\Models\ServiceRecordPart;
use App\Services\Support\Exceptions\InvalidBillingTransition;
use App\Services\Support\MaintenanceBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
    SalesSetting::factory()->create(['default_tax_percent' => '10.00']);
});

function coverage84Method(string $method): ReflectionMethod
{
    return new ReflectionMethod(MaintenanceBillingService::class, $method);
}

it('covers actual and frozen customer-responsibility line builders across coverage sources', function (): void {
    $service = app(MaintenanceBillingService::class);

    $fullyCovered = MaintenanceRecord::factory()->create([
        'coverage_decision' => WarrantyClaimDecision::FullyCovered,
    ]);
    expect(coverage84Method('actualCustomerResponsibilityLines')->invoke($service, $fullyCovered))->toBe([]);

    $record = MaintenanceRecord::factory()->create([
        'coverage_decision' => WarrantyClaimDecision::PartiallyCovered,
    ]);
    $task = MaintenanceTask::factory()->create(['maintenance_record_id' => $record->id]);
    $variant = ProductVariant::factory()->create([
        'name' => 'Coverage 84 part',
        'base_price' => '40.00',
    ]);
    $part = ServiceRecordPart::factory()->for($task, 'maintenanceTask')->create([
        'product_variant_id' => $variant->id,
        'quantity' => '2.000000',
    ]);
    ServiceRecordPart::factory()->for($task, 'maintenanceTask')->reversed()->create();

    $labour = MaintenanceLabourEntry::factory()->create([
        'maintenance_record_id' => $record->id,
        'total_cost_minor' => 20000,
    ]);
    $coveredThirdParty = MaintenanceThirdPartyCost::factory()->create([
        'maintenance_record_id' => $record->id,
        'description' => 'Covered external work',
        'amount_minor' => 10000,
    ]);
    $unassessedThirdParty = MaintenanceThirdPartyCost::factory()->create([
        'maintenance_record_id' => $record->id,
        'description' => 'Unassessed external work',
        'amount_minor' => 5000,
    ]);

    MaintenanceCoverageLine::factory()->for($record, 'maintenanceRecord')->create([
        'category' => WarrantyLineCategory::Part,
        'description' => 'Part coverage',
        'source_type' => ServiceRecordPart::class,
        'source_id' => $part->id,
        'amount_minor' => 8000,
        'coverage_percent' => 50,
        'covered_amount_minor' => 4000,
        'customer_amount_minor' => 4000,
    ]);
    MaintenanceCoverageLine::factory()->for($record, 'maintenanceRecord')->create([
        'category' => WarrantyLineCategory::Labour,
        'description' => 'Labour coverage',
        'source_type' => 'maintenance_labour',
        'source_id' => null,
        'amount_minor' => 20000,
        'coverage_percent' => 25,
        'covered_amount_minor' => 5000,
        'customer_amount_minor' => 15000,
    ]);
    MaintenanceCoverageLine::factory()->for($record, 'maintenanceRecord')->create([
        'category' => WarrantyLineCategory::ThirdParty,
        'description' => 'Third-party coverage',
        'source_type' => MaintenanceThirdPartyCost::class,
        'source_id' => $coveredThirdParty->id,
        'amount_minor' => 10000,
        'coverage_percent' => 100,
        'covered_amount_minor' => 10000,
        'customer_amount_minor' => 0,
    ]);
    MaintenanceCoverageLine::factory()->for($record, 'maintenanceRecord')->create([
        'category' => WarrantyLineCategory::Travel,
        'description' => 'Standalone travel',
        'source_type' => null,
        'source_id' => null,
        'amount_minor' => 3000,
        'coverage_percent' => 0,
        'covered_amount_minor' => 0,
        'customer_amount_minor' => 3000,
    ]);
    MaintenanceCoverageLine::factory()->for($record, 'maintenanceRecord')->create([
        'category' => WarrantyLineCategory::Other,
        'description' => 'Ignored source',
        'source_type' => 'other_source',
        'source_id' => 999,
        'amount_minor' => 1000,
        'coverage_percent' => 0,
        'covered_amount_minor' => 0,
        'customer_amount_minor' => 1000,
    ]);
    MaintenanceCoverageLine::factory()->for($record, 'maintenanceRecord')->create([
        'category' => WarrantyLineCategory::Other,
        'description' => 'Zero customer',
        'source_type' => null,
        'source_id' => null,
        'amount_minor' => 1000,
        'coverage_percent' => 100,
        'covered_amount_minor' => 1000,
        'customer_amount_minor' => 0,
    ]);

    $actual = coverage84Method('actualCustomerResponsibilityLines')->invoke($service, $record->refresh());

    expect($actual)->toHaveCount(4)
        ->and(collect($actual)->pluck('description')->filter()->values()->all())
        ->toContain('Labour', 'Unassessed external work', 'Standalone travel');

    $partLine = collect($actual)->first(fn (array $line): bool => ($line['product_variant_id'] ?? null) === $variant->id);
    expect($partLine)->not->toBeNull()
        ->and($partLine['unit_price'])->toBeGreaterThan(0)
        ->and($partLine['unit_price'])->toBeLessThan(40.01);

    $frozen = MaintenanceRecord::factory()->create([
        'coverage_decision' => WarrantyClaimDecision::Rejected,
    ]);
    $frozenTask = MaintenanceTask::factory()->create(['maintenance_record_id' => $frozen->id]);
    ServiceRecordPart::factory()->for($frozenTask, 'maintenanceTask')->create([
        'product_variant_id' => ProductVariant::factory()->create(['base_price' => '15.00'])->id,
        'quantity' => '1.000000',
    ]);
    MaintenanceLabourEntry::factory()->create([
        'maintenance_record_id' => $frozen->id,
        'total_cost_minor' => 5000,
    ]);
    MaintenanceThirdPartyCost::factory()->create([
        'maintenance_record_id' => $frozen->id,
        'description' => 'Rejected external cost',
        'amount_minor' => 2500,
    ]);

    $rejectedLines = coverage84Method('customerResponsibilityLines')->invoke($service, $frozen->refresh());
    expect($rejectedLines)->toHaveCount(3);

    $partialWithoutLines = MaintenanceRecord::factory()->create([
        'coverage_decision' => WarrantyClaimDecision::PartiallyCovered,
    ]);
    expect(coverage84Method('customerResponsibilityLines')->invoke($service, $partialWithoutLines))->toBe([]);

    $unassessed = MaintenanceRecord::factory()->create([
        'coverage_decision' => WarrantyClaimDecision::PendingDiagnosis,
    ]);
    MaintenanceLabourEntry::factory()->create([
        'maintenance_record_id' => $unassessed->id,
        'total_cost_minor' => 1000,
    ]);
    expect(coverage84Method('customerResponsibilityLines')->invoke($service, $unassessed->refresh()))->toHaveCount(1);

    // Keep the created model referenced so factories are not optimized away by future refactors.
    expect($labour->exists && $unassessedThirdParty->exists)->toBeTrue();
});

it('covers billing and quotation guard branches', function (): void {
    $service = app(MaintenanceBillingService::class);

    $open = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Open,
        'billing_type' => MaintenanceBillingType::Unbilled,
    ]);
    expect(fn (): mixed => coverage84Method('assertBillable')->invoke($service, $open))
        ->toThrow(InvalidBillingTransition::class)
        ->and(fn (): mixed => coverage84Method('assertQuotable')->invoke($service, $open))
        ->toThrow(ValidationException::class, 'quotation can be created');

    $warrantySettled = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Closed,
        'billing_type' => MaintenanceBillingType::WarrantyCovered,
    ]);
    expect(fn (): mixed => coverage84Method('assertBillable')->invoke($service, $warrantySettled))
        ->toThrow(InvalidBillingTransition::class);

    $invoiced = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Closed,
        'billing_type' => MaintenanceBillingType::Invoiced,
    ]);
    expect(fn (): mixed => coverage84Method('assertBillable')->invoke($service, $invoiced))
        ->toThrow(InvalidBillingTransition::class)
        ->and(fn (): mixed => coverage84Method('assertQuotable')->invoke($service, $invoiced))
        ->toThrow(InvalidBillingTransition::class);

    $fullyCovered = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Closed,
        'billing_type' => MaintenanceBillingType::Unbilled,
        'coverage_decision' => WarrantyClaimDecision::FullyCovered,
    ]);
    expect(fn (): mixed => coverage84Method('assertQuotable')->invoke($service, $fullyCovered))
        ->toThrow(ValidationException::class, 'no customer responsibility');

    $quoted = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Closed,
        'billing_type' => MaintenanceBillingType::Quoted,
    ]);
    expect(fn (): mixed => coverage84Method('assertQuotable')->invoke($service, $quoted))
        ->toThrow(ValidationException::class, 'quotation already exists');

    // Invoiceable deliberately permits Quoted requests so quotation-specific checks run later.
    expect(coverage84Method('assertInvoiceable')->invoke($service, $quoted))->toBeNull();
});

it('covers quotation ancestry line totals ticket-fee tax branches and zero third-party lines', function (): void {
    $service = app(MaintenanceBillingService::class);

    $accepted = Quotation::factory()->create(['status' => QuotationStatus::Accepted]);
    $requoted = Quotation::factory()->create([
        'status' => QuotationStatus::Rejected,
        'requoted_from_id' => $accepted->id,
    ]);
    $orphan = Quotation::factory()->create(['status' => QuotationStatus::Rejected]);

    expect(coverage84Method('latestAcceptedQuotation')->invoke($service, $accepted)->is($accepted))->toBeTrue()
        ->and(coverage84Method('latestAcceptedQuotation')->invoke($service, $requoted)->is($accepted))->toBeTrue()
        ->and(coverage84Method('latestAcceptedQuotation')->invoke($service, $orphan))->toBeNull();

    $unsaved = new Quotation;
    expect(coverage84Method('latestAcceptedQuotation')->invoke($service, $unsaved))->toBeNull();

    $total = coverage84Method('linesTotal')->invoke($service, [
        ['quantity' => 2, 'unit_price' => 10.25, 'tax_amount' => 1.03],
        ['quantity' => 1, 'unit_price' => 4.50, 'tax_amount' => 0.23],
    ]);
    expect($total)->toBe(26.26);

    $record = MaintenanceRecord::factory()->create();
    MaintenanceThirdPartyCost::factory()->create([
        'maintenance_record_id' => $record->id,
        'description' => 'Zero',
        'amount_minor' => 0,
    ]);
    MaintenanceThirdPartyCost::factory()->create([
        'maintenance_record_id' => $record->id,
        'description' => 'Billable',
        'amount_minor' => 2500,
    ]);

    $thirdPartyLines = coverage84Method('thirdPartyLines')->invoke($service, $record->refresh());
    expect($thirdPartyLines)->toHaveCount(1)
        ->and($thirdPartyLines[0]['description'])->toBe('Billable');

    $emptyLabour = coverage84Method('labourLine')->invoke($service, MaintenanceRecord::factory()->create());
    expect($emptyLabour)->toBe([]);
});
