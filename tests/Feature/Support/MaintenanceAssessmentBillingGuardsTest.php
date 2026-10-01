<?php

declare(strict_types=1);

use App\Data\Support\LabourEntryData;
use App\Data\Support\ThirdPartyCostData;
use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceStatus;
use App\Enums\PaymentLinkStatus;
use App\Enums\QuotationStatus;
use App\Enums\WarrantyClaimDecision;
use App\Enums\WarrantyCoverageSource;
use App\Enums\WarrantyFailureCategory;
use App\Enums\WarrantyLineCategory;
use App\Enums\WarrantyStatus;
use App\Filament\Resources\MaintenanceRequests\Pages\ViewMaintenanceRequest;
use App\Filament\Resources\MaintenanceRequests\RelationManagers\LabourEntriesRelationManager;
use App\Filament\Resources\MaintenanceRequests\RelationManagers\ServiceRecordsRelationManager;
use App\Filament\Resources\MaintenanceRequests\RelationManagers\ThirdPartyCostsRelationManager;
use App\Models\InventoryLot;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\MaintenanceCoverageLine;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\SalesSetting;
use App\Models\ServiceRecordPart;
use App\Models\Ticket;
use App\Models\TicketPaymentLink;
use App\Models\User;
use App\Policies\MaintenanceRecordPolicy;
use App\Services\Support\Exceptions\InvalidBillingTransition;
use App\Services\Support\Exceptions\InvalidStatusTransition;
use App\Services\Support\MaintenanceBillingService;
use App\Services\Support\MaintenanceCostService;
use App\Services\Support\MaintenanceRecordService;
use App\Services\Support\ServiceRecordPartService;
use App\Services\Support\ServiceRecordService;
use App\Services\Support\WarrantyClaimService;
use Database\Seeders\SupportPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
    SalesSetting::factory()->create([
        'default_tax_percent' => '10.00',
        'default_quotation_validity_days' => 30,
    ]);
});

function allowAllGateChecks(): void
{
    Gate::before(static fn (): bool => true);
}

function guardTestReviewer(): User
{
    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');

    return $reviewer;
}

function guardTestManager(): User
{
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    return $manager;
}

/** A diagnosed, partially covered job awaiting customer approval with a 75.00 customer share. */
function partiallyCoveredAwaitingApproval(User $actor): MaintenanceRecord
{
    $record = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Open,
        'billing_type' => MaintenanceBillingType::Unbilled,
        'warranty_status' => WarrantyStatus::Covered,
        'warranty_expiry_date' => today()->addYear(),
    ]);
    $service = app(WarrantyClaimService::class);

    $service->recordDiagnosis($record, [
        'diagnosis_summary' => 'Power module fails under load.',
        'root_cause' => 'Component failure.',
        'failure_category' => WarrantyFailureCategory::NormalComponentFailure->value,
    ], $actor);

    return $service->decideCoverage($record, [
        'coverage_decision' => WarrantyClaimDecision::PartiallyCovered->value,
        'coverage_reason' => 'Part covered, travel excluded.',
        'customer_coverage_explanation' => 'You only pay travel.',
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
}

/** @return array<string, mixed> */
function fullyCoveredAssessment(): array
{
    return [
        'coverage_decision' => WarrantyClaimDecision::FullyCovered->value,
        'coverage_reason' => 'Attempt to widen coverage after quoting.',
        'customer_coverage_explanation' => 'Covered.',
        'coverage_lines' => [[
            'category' => WarrantyLineCategory::Part->value,
            'description' => 'Everything',
            'amount_minor' => 37500,
            'coverage_percent' => 100,
        ]],
    ];
}

function closedRecordWithCustomerShare(): MaintenanceRecord
{
    $record = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Closed,
        'billing_type' => MaintenanceBillingType::Unbilled,
        'coverage_decision' => WarrantyClaimDecision::Rejected,
    ]);
    MaintenanceCoverageLine::factory()->for($record)->create([
        'amount_minor' => 10000,
        'coverage_percent' => 0,
        'covered_amount_minor' => 0,
        'customer_amount_minor' => 10000,
    ]);

    return $record;
}

function closedFullyCoveredRecord(): MaintenanceRecord
{
    return MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Closed,
        'billing_type' => MaintenanceBillingType::Unbilled,
        'warranty_status' => WarrantyStatus::Covered,
        'warranty_expiry_date' => today()->addMonth(),
        'coverage_decision' => WarrantyClaimDecision::FullyCovered,
        'coverage_source' => WarrantyCoverageSource::SellerWarranty,
    ]);
}

// ---------------------------------------------------------------------------
// L15 — assessment is frozen once a quotation / invoice exists
// ---------------------------------------------------------------------------

it('refuses to rebuild the diagnosis or coverage assessment once a quotation exists, even from a stale model', function (): void {
    allowAllGateChecks();
    $actor = guardTestManager();
    $record = partiallyCoveredAwaitingApproval($actor);
    $stale = MaintenanceRecord::query()->findOrFail($record->getKey());

    app(MaintenanceBillingService::class)->createQuotation($record, $actor);

    expect($stale->billing_type)->toBe(MaintenanceBillingType::Unbilled);

    expect(fn () => app(WarrantyClaimService::class)->recordDiagnosis($stale, [
        'diagnosis_summary' => 'Rewritten findings.',
        'root_cause' => 'Rewritten cause.',
        'failure_category' => WarrantyFailureCategory::NormalComponentFailure->value,
    ], $actor))->toThrow(ValidationException::class, 'cannot be reassessed');

    expect(fn () => app(WarrantyClaimService::class)->decideCoverage($stale, fullyCoveredAssessment(), $actor))
        ->toThrow(ValidationException::class, 'cannot be reassessed');

    $record->refresh();

    expect($record->coverage_decision)->toBe(WarrantyClaimDecision::PartiallyCovered)
        ->and($record->diagnosis_summary)->toBe('Power module fails under load.')
        ->and(app(WarrantyClaimService::class)->coverageSummary($record))->toBe([
            'total_amount_minor' => 37500,
            'covered_amount_minor' => 30000,
            'customer_amount_minor' => 7500,
        ]);
});

it('hides the diagnosis and coverage actions once the request has a quotation', function (): void {
    allowAllGateChecks();
    $actor = guardTestManager();
    $record = partiallyCoveredAwaitingApproval($actor);

    Livewire::actingAs($actor)
        ->test(ViewMaintenanceRequest::class, ['record' => $record->getKey()])
        ->assertActionVisible('recordDiagnosis')
        ->assertActionVisible('determineCoverage');

    $record->update(['billing_type' => MaintenanceBillingType::Quoted]);

    Livewire::actingAs($actor)
        ->test(ViewMaintenanceRequest::class, ['record' => $record->getKey()])
        ->assertActionHidden('recordDiagnosis')
        ->assertActionHidden('determineCoverage');
});

// ---------------------------------------------------------------------------
// L17 — billing guards run against the locked row, not the stale model
// ---------------------------------------------------------------------------

it('creates only one invoice when the same stale model is billed twice', function (): void {
    allowAllGateChecks();
    $actor = guardTestManager();
    $record = closedRecordWithCustomerShare();
    $stale = MaintenanceRecord::query()->findOrFail($record->getKey());

    $invoice = app(MaintenanceBillingService::class)->createInvoice($record, $actor);

    expect($invoice)->toBeInstanceOf(Invoice::class)
        ->and(fn () => app(MaintenanceBillingService::class)->createInvoice($stale, $actor))
        ->toThrow(InvalidBillingTransition::class)
        ->and(Invoice::query()->where('maintenance_record_id', $record->getKey())->count())->toBe(1);
});

it('creates only one quotation when the same stale model is quoted twice', function (): void {
    allowAllGateChecks();
    $actor = guardTestManager();
    $record = partiallyCoveredAwaitingApproval($actor);
    $stale = MaintenanceRecord::query()->findOrFail($record->getKey());

    $quotation = app(MaintenanceBillingService::class)->createQuotation($record, $actor);

    expect(fn () => app(MaintenanceBillingService::class)->createQuotation($stale, $actor))
        ->toThrow(ValidationException::class, 'already exists')
        ->and($record->refresh()->quotation_id)->toBe($quotation->getKey())
        ->and(Activity::query()->where('description', 'support.maintenance_record.quoted')->count())->toBe(1);
});

it('allows a fresh quotation once the previous one was rejected', function (): void {
    allowAllGateChecks();
    $actor = guardTestManager();
    $record = partiallyCoveredAwaitingApproval($actor);
    $first = app(MaintenanceBillingService::class)->createQuotation($record, $actor);
    $first->update(['status' => QuotationStatus::Rejected]);

    $second = app(MaintenanceBillingService::class)->createQuotation($record->refresh(), $actor);

    expect($second->getKey())->not->toBe($first->getKey())
        ->and($record->refresh()->quotation_id)->toBe($second->getKey());
});

it('settles a covered repair only once when the same stale model is settled twice', function (): void {
    allowAllGateChecks();
    $actor = guardTestManager();
    $record = closedFullyCoveredRecord();
    $stale = MaintenanceRecord::query()->findOrFail($record->getKey());

    app(MaintenanceBillingService::class)->settleCoverage($record, $actor, 'Settled once.');

    expect(fn () => app(MaintenanceBillingService::class)->settleCoverage($stale, $actor, 'Settled twice.'))
        ->toThrow(InvalidBillingTransition::class)
        ->and(Activity::query()->where('description', 'support.maintenance_record.coverage_settled')->count())->toBe(1);
});

it('marks a ticket-settled request only once when the same stale model is processed twice', function (): void {
    allowAllGateChecks();
    $actor = guardTestManager();
    $ticket = Ticket::factory()->chargeable()->create();
    TicketPaymentLink::factory()->for($ticket)->settled()->create();
    $record = MaintenanceRecord::factory()->create([
        'ticket_id' => $ticket->getKey(),
        'customer_id' => $ticket->customer_id,
        'status' => MaintenanceStatus::Closed,
        'billing_type' => MaintenanceBillingType::Unbilled,
    ]);
    $stale = MaintenanceRecord::query()->findOrFail($record->getKey());

    app(MaintenanceBillingService::class)->markTicketSettled($record, $actor, 'Fee already collected.');

    expect(fn () => app(MaintenanceBillingService::class)->markTicketSettled($stale, $actor, 'Fee already collected.'))
        ->toThrow(InvalidBillingTransition::class)
        ->and(Activity::query()->where('description', 'support.maintenance_record.ticket_settled')->count())->toBe(1);
});

it('refuses ticket settlement when the linked payment is not settled', function (): void {
    allowAllGateChecks();
    $actor = guardTestManager();
    $ticket = Ticket::factory()->chargeable()->create();
    TicketPaymentLink::factory()->for($ticket)->create(['status' => PaymentLinkStatus::Pending]);
    $record = MaintenanceRecord::factory()->create([
        'ticket_id' => $ticket->getKey(),
        'customer_id' => $ticket->customer_id,
        'status' => MaintenanceStatus::Closed,
        'billing_type' => MaintenanceBillingType::Unbilled,
    ]);

    expect(fn () => app(MaintenanceBillingService::class)->markTicketSettled($record, $actor, 'Fee already collected.'))
        ->toThrow(ValidationException::class, 'settled support payment');
});

it('reclassifies a warranty-covered request only once when the same stale model is processed twice', function (): void {
    allowAllGateChecks();
    $actor = guardTestManager();
    $record = closedFullyCoveredRecord();
    $billing = app(MaintenanceBillingService::class);
    $billing->settleCoverage($record, $actor, 'Covered.');

    $record->refresh();
    $stale = MaintenanceRecord::query()->findOrFail($record->getKey());

    $billing->reclassifyWarrantyForBilling($record, $actor, 'Customer pays.');

    expect(fn () => $billing->reclassifyWarrantyForBilling($stale, $actor, 'Customer pays again.'))
        ->toThrow(InvalidBillingTransition::class)
        ->and(Activity::query()->where('description', 'support.maintenance_record.warranty_reclassified')->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// L23 — markWarrantyCovered authorizes first and is atomic
// ---------------------------------------------------------------------------

it('leaves the record untouched when an unauthorized user marks it warranty-covered', function (): void {
    $record = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Closed,
        'billing_type' => MaintenanceBillingType::Unbilled,
        'coverage_decision' => WarrantyClaimDecision::PendingDiagnosis,
    ]);

    expect(fn () => app(MaintenanceBillingService::class)->markWarrantyCovered($record, guardTestReviewer(), 'No permission.'))
        ->toThrow(AuthorizationException::class);

    $record->refresh();

    expect($record->coverage_decision)->toBe(WarrantyClaimDecision::PendingDiagnosis)
        ->and($record->coverage_reason)->toBeNull()
        ->and($record->billing_type)->toBe(MaintenanceBillingType::Unbilled);
});

it('rolls back the implied coverage decision when the request cannot be billed yet', function (): void {
    allowAllGateChecks();
    $record = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Open,
        'billing_type' => MaintenanceBillingType::Unbilled,
        'coverage_decision' => WarrantyClaimDecision::PendingDiagnosis,
    ]);

    expect(fn () => app(MaintenanceBillingService::class)->markWarrantyCovered($record, guardTestManager(), 'Too early.'))
        ->toThrow(InvalidBillingTransition::class);

    expect($record->refresh()->coverage_decision)->toBe(WarrantyClaimDecision::PendingDiagnosis);
});

it('marks a closed request warranty-covered, deriving the coverage decision from its warranty status', function (): void {
    allowAllGateChecks();
    $manager = guardTestManager();
    $covered = MaintenanceRecord::factory()->covered()->create([
        'status' => MaintenanceStatus::Closed,
        'coverage_decision' => WarrantyClaimDecision::PendingDiagnosis,
    ]);
    $thirdParty = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Closed,
        'coverage_decision' => WarrantyClaimDecision::PendingDiagnosis,
    ]);

    app(MaintenanceBillingService::class)->markWarrantyCovered($covered, $manager, 'Seller warranty.');
    app(MaintenanceBillingService::class)->markWarrantyCovered($thirdParty, $manager, 'Manufacturer warranty.');

    expect($covered->refresh()->coverage_decision)->toBe(WarrantyClaimDecision::FullyCovered)
        ->and($covered->coverage_source)->toBe(WarrantyCoverageSource::SellerWarranty)
        ->and($covered->billing_type)->toBe(MaintenanceBillingType::WarrantyCovered)
        ->and($thirdParty->refresh()->coverage_decision)->toBe(WarrantyClaimDecision::ThirdPartyWarranty)
        ->and($thirdParty->coverage_source)->toBe(WarrantyCoverageSource::ManufacturerWarranty);

    expect(fn () => app(MaintenanceBillingService::class)->markWarrantyCovered($thirdParty, $manager, ' '))
        ->toThrow(InvalidBillingTransition::class);
});

// ---------------------------------------------------------------------------
// L18 — no repair work or part consumption while awaiting approval
// ---------------------------------------------------------------------------

it('refuses to start a service record while the parent request is awaiting approval, closed or cancelled', function (MaintenanceStatus $parentStatus): void {
    allowAllGateChecks();
    $record = MaintenanceRecord::factory()->create(['status' => $parentStatus]);
    $task = MaintenanceTask::factory()->for($record, 'maintenanceRecord')->create(['status' => MaintenanceStatus::Open]);

    expect(fn () => app(ServiceRecordService::class)->transition($task, MaintenanceStatus::InProgress, guardTestManager()))
        ->toThrow(InvalidStatusTransition::class, $parentStatus->value);

    expect($task->refresh()->status)->toBe(MaintenanceStatus::Open)
        ->and($record->refresh()->status)->toBe($parentStatus);
})->with([
    'awaiting approval' => MaintenanceStatus::AwaitingApproval,
    'closed' => MaintenanceStatus::Closed,
    'cancelled' => MaintenanceStatus::Cancelled,
]);

it('lets a task start once the parent request is ready for repair or has no coverage assessment', function (MaintenanceStatus $parentStatus): void {
    allowAllGateChecks();
    $record = MaintenanceRecord::factory()->create(['status' => $parentStatus]);
    $task = MaintenanceTask::factory()->for($record, 'maintenanceRecord')->create(['status' => MaintenanceStatus::Open]);

    app(ServiceRecordService::class)->transition($task, MaintenanceStatus::InProgress, guardTestManager());

    expect($task->refresh()->status)->toBe(MaintenanceStatus::InProgress);
})->with([
    'ready for repair' => MaintenanceStatus::ReadyForRepair,
    'legacy open' => MaintenanceStatus::Open,
]);

it('refuses to consume parts while the parent request is awaiting approval', function (): void {
    allowAllGateChecks();
    $stock = InventoryStock::factory()->create([
        'on_hand_quantity' => 10,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 10,
    ]);
    $lot = InventoryLot::factory()->for($stock->productVariant)->for($stock->warehouse)->create([
        'on_hand_quantity' => '10',
        'reserved_quantity' => '0.000000',
        'expires_at' => null,
    ]);
    $record = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::AwaitingApproval]);
    $task = MaintenanceTask::factory()->for($record, 'maintenanceRecord')->create(['status' => MaintenanceStatus::InProgress]);

    expect(fn () => app(ServiceRecordPartService::class)->consume(
        $task,
        $stock->product_variant_id,
        $stock->warehouse_id,
        2.0,
        guardTestManager(),
        $lot->getKey(),
    ))->toThrow(InvalidStatusTransition::class, 'awaiting_approval');

    expect(ServiceRecordPart::query()->count())->toBe(0)
        ->and($stock->refresh()->available_quantity)->toEqualWithDelta(10.0, 0.001);

    $record->update(['status' => MaintenanceStatus::ReadyForRepair]);

    app(ServiceRecordPartService::class)->consume($task, $stock->product_variant_id, $stock->warehouse_id, 2.0, guardTestManager(), $lot->getKey());

    expect(ServiceRecordPart::query()->count())->toBe(1);
});

it('hides the start-work action on the maintenance request while it is awaiting approval', function (): void {
    allowAllGateChecks();
    $actor = guardTestManager();
    $record = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::AwaitingApproval]);
    $task = MaintenanceTask::factory()->for($record, 'maintenanceRecord')->create(['status' => MaintenanceStatus::Open]);

    Livewire::actingAs($actor)
        ->test(ServiceRecordsRelationManager::class, ['ownerRecord' => $record, 'pageClass' => ViewMaintenanceRequest::class])
        ->assertActionHidden(TestAction::make('startProgress')->table($task));

    $record->update(['status' => MaintenanceStatus::ReadyForRepair]);

    Livewire::actingAs($actor)
        ->test(ServiceRecordsRelationManager::class, ['ownerRecord' => $record->refresh(), 'pageClass' => ViewMaintenanceRequest::class])
        ->assertActionVisible(TestAction::make('startProgress')->table($task));
});

// ---------------------------------------------------------------------------
// L19 — closed / cancelled / billed requests are immutable
// ---------------------------------------------------------------------------

it('refuses to edit a request that was closed or billed after the stale model was loaded', function (): void {
    allowAllGateChecks();
    $actor = guardTestManager();
    $record = MaintenanceRecord::factory()->create(['description' => 'Original description']);
    $stale = MaintenanceRecord::query()->findOrFail($record->getKey());

    $record->update(['status' => MaintenanceStatus::Closed, 'billing_type' => MaintenanceBillingType::Invoiced]);

    expect(fn () => app(MaintenanceRecordService::class)->update($stale, ['description' => 'Tampered description'], $actor))
        ->toThrow(ValidationException::class, 'cannot be edited')
        ->and(fn () => app(MaintenanceRecordService::class)->overrideWarranty($stale, WarrantyStatus::Expired, null, 'Late correction', $actor))
        ->toThrow(ValidationException::class, 'cannot be edited');

    expect($record->refresh()->description)->toBe('Original description')
        ->and($record->warranty_status)->toBe(WarrantyStatus::Unknown);
});

it('still edits and corrects the warranty of an open, unbilled request', function (): void {
    allowAllGateChecks();
    $actor = guardTestManager();
    $record = MaintenanceRecord::factory()->create(['description' => 'Original description']);

    $updated = app(MaintenanceRecordService::class)->update($record, ['description' => 'Clarified description'], $actor);
    app(MaintenanceRecordService::class)->overrideWarranty($record, WarrantyStatus::Expired, null, 'Expired last week', $actor);

    expect($updated->description)->toBe('Clarified description')
        ->and($record->refresh()->warranty_status)->toBe(WarrantyStatus::Expired);
});

it('refuses to record labour or third-party costs on a closed, cancelled or billed request', function (array $state): void {
    allowAllGateChecks();
    $actor = guardTestManager();
    $record = MaintenanceRecord::factory()->create($state);
    $employee = User::factory()->create();

    expect(fn () => app(MaintenanceCostService::class)->recordLabour(new LabourEntryData(
        maintenanceRecordId: $record->getKey(),
        serviceRecordId: null,
        employeeId: $employee->getKey(),
        performedOn: now()->toDateString(),
        minutes: 30,
        hourlyRateMinor: 6000,
    ), $actor))->toThrow(ValidationException::class, 'Costs cannot be recorded');

    expect(fn () => app(MaintenanceCostService::class)->recordThirdPartyCost(new ThirdPartyCostData(
        maintenanceRecordId: $record->getKey(),
        description: 'Courier',
        amountMinor: 5000,
        incurredOn: now()->toDateString(),
    ), $actor))->toThrow(ValidationException::class, 'Costs cannot be recorded');

    expect($record->labourEntries()->count())->toBe(0)
        ->and($record->thirdPartyCosts()->count())->toBe(0);
})->with([
    'closed' => [['status' => MaintenanceStatus::Closed]],
    'cancelled' => [['status' => MaintenanceStatus::Cancelled]],
    'billed' => [['status' => MaintenanceStatus::AwaitingApproval, 'billing_type' => MaintenanceBillingType::Quoted]],
]);

it('hides the cost entry actions on a billed request', function (): void {
    allowAllGateChecks();
    $actor = guardTestManager();
    $record = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::InProgress]);

    Livewire::actingAs($actor)
        ->test(LabourEntriesRelationManager::class, ['ownerRecord' => $record, 'pageClass' => ViewMaintenanceRequest::class])
        ->assertActionVisible(TestAction::make('recordLabour')->table());
    Livewire::actingAs($actor)
        ->test(ThirdPartyCostsRelationManager::class, ['ownerRecord' => $record, 'pageClass' => ViewMaintenanceRequest::class])
        ->assertActionVisible(TestAction::make('recordThirdPartyCost')->table());

    $record->update(['billing_type' => MaintenanceBillingType::Invoiced, 'status' => MaintenanceStatus::Closed]);

    Livewire::actingAs($actor)
        ->test(LabourEntriesRelationManager::class, ['ownerRecord' => $record->refresh(), 'pageClass' => ViewMaintenanceRequest::class])
        ->assertActionHidden(TestAction::make('recordLabour')->table());
    Livewire::actingAs($actor)
        ->test(ThirdPartyCostsRelationManager::class, ['ownerRecord' => $record, 'pageClass' => ViewMaintenanceRequest::class])
        ->assertActionHidden(TestAction::make('recordThirdPartyCost')->table());
});

it('makes the policy update ability record-aware while transitions stay available on a quoted request', function (): void {
    $manager = guardTestManager();
    $policy = app(MaintenanceRecordPolicy::class);
    $open = MaintenanceRecord::factory()->create();
    $quoted = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::AwaitingApproval,
        'billing_type' => MaintenanceBillingType::Quoted,
    ]);
    $closed = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::Closed]);
    $cancelled = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::Cancelled]);
    $withInvoice = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::InProgress]);
    $withInvoice->forceFill(['invoice_id' => Invoice::factory()->create()->getKey()])->save();

    expect($policy->update($manager))->toBeTrue()
        ->and($policy->update($manager, $open))->toBeTrue()
        ->and($policy->update($manager, $quoted))->toBeFalse()
        ->and($policy->update($manager, $closed))->toBeFalse()
        ->and($policy->update($manager, $cancelled))->toBeFalse()
        ->and($policy->update($manager, $withInvoice->refresh()))->toBeFalse()
        ->and($policy->transition($manager))->toBeTrue()
        ->and($policy->transition(guardTestReviewer()))->toBeFalse();
});

it('refuses to cancel a request or a task while a consumed part has not been reversed', function (): void {
    allowAllGateChecks();
    $actor = guardTestManager();
    $record = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::InProgress]);
    $task = MaintenanceTask::factory()->for($record, 'maintenanceRecord')->create(['status' => MaintenanceStatus::InProgress]);
    $part = ServiceRecordPart::factory()->for($task, 'maintenanceTask')->create();

    expect(fn () => app(MaintenanceRecordService::class)->transition($record, MaintenanceStatus::Cancelled, $actor))
        ->toThrow(InvalidStatusTransition::class, 'consumed parts')
        ->and(fn () => app(ServiceRecordService::class)->transition($task, MaintenanceStatus::Cancelled, $actor))
        ->toThrow(InvalidStatusTransition::class, 'consumed parts')
        ->and($record->refresh()->status)->toBe(MaintenanceStatus::InProgress)
        ->and($task->refresh()->status)->toBe(MaintenanceStatus::InProgress);

    $part->forceFill(['reversed_at' => now()])->save();

    app(ServiceRecordService::class)->transition($task, MaintenanceStatus::Cancelled, $actor);
    app(MaintenanceRecordService::class)->transition($record, MaintenanceStatus::Cancelled, $actor);

    expect($task->refresh()->status)->toBe(MaintenanceStatus::Cancelled)
        ->and($record->refresh()->status)->toBe(MaintenanceStatus::Cancelled);
});

it('refuses a maintenance transition that the committed status no longer allows', function (): void {
    allowAllGateChecks();
    $actor = guardTestManager();
    $record = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::Open]);
    $stale = MaintenanceRecord::query()->findOrFail($record->getKey());

    $record->update(['status' => MaintenanceStatus::Cancelled]);

    expect(fn () => app(MaintenanceRecordService::class)->transition($stale, MaintenanceStatus::InProgress, $actor))
        ->toThrow(InvalidStatusTransition::class)
        ->and($record->refresh()->status)->toBe(MaintenanceStatus::Cancelled);
});

it('refuses to close a request that still has an open service record', function (): void {
    allowAllGateChecks();
    $record = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::InProgress]);
    MaintenanceTask::factory()->for($record, 'maintenanceRecord')->create(['status' => MaintenanceStatus::InProgress]);

    expect(fn () => app(MaintenanceRecordService::class)->transition($record, MaintenanceStatus::Closed, guardTestManager()))
        ->toThrow(InvalidStatusTransition::class);
});

it('refuses seller-warranty settlement without an active warranty, a quotation without customer share, and coverage before diagnosis', function (): void {
    allowAllGateChecks();
    $actor = guardTestManager();

    $expired = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Closed,
        'warranty_status' => WarrantyStatus::Expired,
        'coverage_decision' => WarrantyClaimDecision::FullyCovered,
        'coverage_source' => WarrantyCoverageSource::SellerWarranty,
    ]);
    $nothingToQuote = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Closed,
        'coverage_decision' => WarrantyClaimDecision::Rejected,
    ]);
    $undiagnosed = MaintenanceRecord::factory()->covered()->create();

    expect(fn () => app(MaintenanceBillingService::class)->settleCoverage($expired, $actor, 'Settle.'))
        ->toThrow(ValidationException::class, 'requires an active warranty entitlement')
        ->and(fn () => app(MaintenanceBillingService::class)->createQuotation($nothingToQuote, $actor))
        ->toThrow(ValidationException::class, 'no customer responsibility')
        ->and(fn () => app(WarrantyClaimService::class)->decideCoverage($undiagnosed, fullyCoveredAssessment(), $actor))
        ->toThrow(ValidationException::class, 'Record the diagnosis');
});

it('requires a reason and an expiry date when correcting warranty information', function (): void {
    allowAllGateChecks();
    $actor = guardTestManager();
    $record = MaintenanceRecord::factory()->create();

    expect(fn () => app(MaintenanceRecordService::class)->overrideWarranty($record, WarrantyStatus::Expired, null, ' ', $actor))
        ->toThrow(ValidationException::class, 'reason is required')
        ->and(fn () => app(MaintenanceRecordService::class)->overrideWarranty($record, WarrantyStatus::Covered, null, 'Customer proved purchase date.', $actor))
        ->toThrow(ValidationException::class, 'requires an expiry date');
});
