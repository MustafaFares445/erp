<?php

declare(strict_types=1);

use App\Enums\MaintenanceStatus;
use App\Enums\QuotationStatus;
use App\Enums\WarrantyCoverageSource;
use App\Filament\Resources\MaintenanceRequests\Tables\MaintenanceRequestsTable;
use App\Filament\Widgets\SupportMaintenanceNeedsAttention;
use App\Models\MaintenanceCoverageLine;
use App\Models\MaintenanceRecord;
use App\Models\Quotation;
use App\Models\User;
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

it('covers support attention next-action states and every approval branch', function (): void {
    foreach ([
        [MaintenanceStatus::Diagnosing, 'Determine coverage'],
        [MaintenanceStatus::ReadyForRepair, 'Start repair'],
        [MaintenanceStatus::QualityAssurance, 'Complete QA'],
        [MaintenanceStatus::Closed, 'Review maintenance job'],
    ] as [$status, $expected]) {
        $record = MaintenanceRecord::factory()->create(['status' => $status]);
        expect(batch64Invoke(SupportMaintenanceNeedsAttention::class, 'nextAction', $record))->toBe($expected);
    }

    $covered = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::AwaitingApproval]);
    expect(batch64Invoke(SupportMaintenanceNeedsAttention::class, 'nextAction', $covered))->toBe('Confirm approval');

    $needsQuote = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::AwaitingApproval]);
    batch64CustomerAmount($needsQuote, 10000);
    expect(batch64Invoke(SupportMaintenanceNeedsAttention::class, 'nextAction', $needsQuote->refresh()))->toBe('Create quotation');

    $accepted = Quotation::factory()->accepted()->create([
        'customer_id' => $needsQuote->customer_id,
        'status' => QuotationStatus::Accepted,
    ]);
    $needsQuote->forceFill(['quotation_id' => $accepted->getKey()])->save();
    expect(batch64Invoke(SupportMaintenanceNeedsAttention::class, 'nextAction', $needsQuote->refresh()))
        ->toBe('Mark ready for repair');

    $pendingQuoteRecord = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::AwaitingApproval]);
    batch64CustomerAmount($pendingQuoteRecord, 10000);
    $pendingQuote = Quotation::factory()->create([
        'customer_id' => $pendingQuoteRecord->customer_id,
        'status' => QuotationStatus::Draft,
    ]);
    $pendingQuoteRecord->forceFill(['quotation_id' => $pendingQuote->getKey()])->save();

    expect(batch64Invoke(SupportMaintenanceNeedsAttention::class, 'nextAction', $pendingQuoteRecord->refresh()))
        ->toBe('Waiting quote approval');
});

it('covers maintenance table next-action states approval branches and actor guard', function (): void {
    foreach ([
        [MaintenanceStatus::QualityAssurance, 'Complete QA'],
        [MaintenanceStatus::Closed, 'Commercial follow-up'],
    ] as [$status, $expected]) {
        $record = MaintenanceRecord::factory()->create(['status' => $status]);
        expect(batch64Invoke(MaintenanceRequestsTable::class, 'nextAction', $record))->toBe($expected);
    }

    $record = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::AwaitingApproval]);
    batch64CustomerAmount($record, 5000);
    expect(batch64Invoke(MaintenanceRequestsTable::class, 'nextAction', $record->refresh()))->toBe('Create quotation');

    $accepted = Quotation::factory()->accepted()->create([
        'customer_id' => $record->customer_id,
        'status' => QuotationStatus::Accepted,
    ]);
    $record->forceFill(['quotation_id' => $accepted->getKey()])->save();
    expect(batch64Invoke(MaintenanceRequestsTable::class, 'nextAction', $record->refresh()))
        ->toBe('Mark ready for repair');

    $waiting = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::AwaitingApproval]);
    batch64CustomerAmount($waiting, 5000);
    $draft = Quotation::factory()->create([
        'customer_id' => $waiting->customer_id,
        'status' => QuotationStatus::Draft,
    ]);
    $waiting->forceFill(['quotation_id' => $draft->getKey()])->save();

    expect(batch64Invoke(MaintenanceRequestsTable::class, 'nextAction', $waiting->refresh()))
        ->toBe('Waiting quote approval');

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
