<?php

declare(strict_types=1);

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceStatus;
use App\Enums\QuotationStatus;
use App\Enums\WarrantyClaimDecision;
use App\Enums\WarrantyCoverageSource;
use App\Filament\Resources\MaintenanceRequests\Tables\MaintenanceRequestsTable;
use App\Models\MaintenanceCoverageLine;
use App\Models\MaintenanceRecord;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Support\MaintenanceNextActionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

function batch64Invoke(string $class, string $method, mixed ...$arguments): mixed
{
    return new ReflectionMethod($class, $method)->invoke(null, ...$arguments);
}

function batch64CustomerAmount(MaintenanceRecord $record, int $customerMinor): void
{
    MaintenanceCoverageLine::factory()->for($record)->create([
        'description' => 'Coverage helper line',
        'amount_minor' => $customerMinor,
        'coverage_percent' => 0,
        'covered_amount_minor' => 0,
        'customer_amount_minor' => $customerMinor,
        'coverage_source' => WarrantyCoverageSource::CustomerPaid,
    ]);
}

it('covers maintenance next-action states and every approval branch', function (): void {
    $resolver = app(MaintenanceNextActionResolver::class);

    foreach ([
        [MaintenanceStatus::Open, 'Record diagnosis'],
        [MaintenanceStatus::Diagnosing, 'Determine coverage'],
        [MaintenanceStatus::ReadyForRepair, 'Start repair'],
        [MaintenanceStatus::InProgress, 'Send to QA'],
        [MaintenanceStatus::QualityAssurance, 'Complete QA'],
        [MaintenanceStatus::Cancelled, 'No action — cancelled'],
    ] as [$status, $expected]) {
        $record = MaintenanceRecord::factory()->create(['status' => $status]);
        expect($resolver->resolve($record))->toBe($expected);
    }

    $covered = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::AwaitingApproval]);
    expect($resolver->resolve($covered))->toBe('Confirm approval');

    $needsQuote = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::AwaitingApproval]);
    batch64CustomerAmount($needsQuote, 10000);
    expect($resolver->resolve($needsQuote->refresh()))->toBe('Create quotation');

    $accepted = Quotation::factory()->accepted()->create([
        'customer_id' => $needsQuote->customer_id,
        'status' => QuotationStatus::Accepted,
    ]);
    $needsQuote->forceFill(['quotation_id' => $accepted->getKey()])->save();
    expect($resolver->resolve($needsQuote->refresh()))->toBe('Mark ready for repair');

    $pendingQuoteRecord = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::AwaitingApproval]);
    batch64CustomerAmount($pendingQuoteRecord, 10000);
    $pendingQuote = Quotation::factory()->create([
        'customer_id' => $pendingQuoteRecord->customer_id,
        'status' => QuotationStatus::Draft,
    ]);
    $pendingQuoteRecord->forceFill(['quotation_id' => $pendingQuote->getKey()])->save();

    expect($resolver->resolve($pendingQuoteRecord->refresh()))->toBe('Waiting quote approval');
});

it('covers the commercial follow-up of a closed maintenance request', function (): void {
    $resolver = app(MaintenanceNextActionResolver::class);

    $closed = static fn (MaintenanceBillingType $billing, WarrantyClaimDecision $decision = WarrantyClaimDecision::PendingDiagnosis): MaintenanceRecord => MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Closed,
        'billing_type' => $billing,
        'coverage_decision' => $decision,
    ]);

    expect($resolver->resolve($closed(MaintenanceBillingType::Invoiced)))->toBe('Commercial follow-up complete')
        ->and($resolver->resolve($closed(MaintenanceBillingType::WarrantyCovered)))->toBe('Commercial follow-up complete');

    foreach ([
        WarrantyClaimDecision::FullyCovered,
        WarrantyClaimDecision::Goodwill,
        WarrantyClaimDecision::ThirdPartyWarranty,
        WarrantyClaimDecision::ServiceContract,
    ] as $decision) {
        expect($resolver->resolve($closed(MaintenanceBillingType::Unbilled, $decision)))->toBe('Settle covered repair');
    }

    foreach ([WarrantyClaimDecision::PartiallyCovered, WarrantyClaimDecision::Rejected] as $decision) {
        expect($resolver->resolve($closed(MaintenanceBillingType::Quoted, $decision)))->toBe('Create final customer invoice');
    }

    expect($resolver->resolve($closed(MaintenanceBillingType::Unbilled)))->toBe('Review billing');
});

it('covers the maintenance table actor guard', function (): void {
    auth()->logout();

    expect(fn (): mixed => batch64Invoke(MaintenanceRequestsTable::class, 'currentActor'))
        ->toThrow(LogicException::class, 'authenticated User');
});

it('covers caught invalid maintenance-table transition without changing the record', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $record = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::Open]);
    batch64Invoke(MaintenanceRequestsTable::class, 'applyTransition', $record, MaintenanceStatus::Closed);

    expect($record->refresh()->status)->toBe(MaintenanceStatus::Open);
});
