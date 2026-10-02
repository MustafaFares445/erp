<?php

declare(strict_types=1);

use App\Enums\PaymentLinkStatus;
use App\Enums\SerializedCustodyType;
use App\Enums\TicketStatus;
use App\Enums\WarrantyClaimDecision;
use App\Enums\WarrantyCoverageSource;
use App\Enums\WarrantyEntitlementState;
use App\Enums\WarrantyRecoveryStatus;
use App\Models\CustomerProfile;
use App\Models\EmployeeProfile;
use App\Models\MaintenanceRecord;
use App\Models\SerializedInventoryUnit;
use App\Models\Supplier;
use App\Models\Ticket;
use App\Models\TicketAssignment;
use App\Models\TicketPaymentLink;
use App\Models\User;
use App\Models\WarrantyEntitlement;
use App\Models\WarrantyRecoveryClaim;
use App\Services\Support\Exceptions\InvalidStatusTransition;
use App\Services\Support\TicketLifecycleService;
use App\Services\Support\TicketPaymentService;
use App\Services\Support\WarrantyEntitlementService;
use App\Services\Support\WarrantyRecoveryService;
use Database\Seeders\SlaPolicySeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
    (new SlaPolicySeeder)->run();
    $this->paymentMethod = configurePaymentAccounting();
});

function raceTestManager(): User
{
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    return $manager;
}

/**
 * Runs $race once, in the window after the service has authorized the call but
 * before it opens its transaction, simulating a concurrent request.
 */
function runOnceBetweenAuthorizeAndWrite(string $ability, Closure $race): void
{
    $fired = false;

    Gate::before(static function (mixed $user, string $checkedAbility) use (&$fired, $ability, $race): null {
        if (! $fired && $checkedAbility === $ability) {
            $fired = true;
            $race();
        }

        return null;
    });
}

// ---------------------------------------------------------------------------
// L21 — ticket lifecycle re-reads under lock
// ---------------------------------------------------------------------------

it('keeps a settled payment link settled when a cancel races the settlement', function (): void {
    $manager = raceTestManager();
    $ticket = Ticket::factory()->chargeable()->create();
    $link = TicketPaymentLink::factory()->for($ticket)->create();

    $settler = User::factory()->admin()->create();

    runOnceBetweenAuthorizeAndWrite('update', function () use ($link, $settler): void {
        app(TicketPaymentService::class)->settle($link, 'REF-RACE', $settler);
    });

    app(TicketLifecycleService::class)->transition($ticket, TicketStatus::Cancelled, $manager);

    expect($link->refresh()->status)->toBe(PaymentLinkStatus::Settled)
        ->and($ticket->refresh()->status)->toBe(TicketStatus::Cancelled);
});

it('only cancels a payment link that is still pending', function (): void {
    $ticket = Ticket::factory()->chargeable()->create();
    $pending = TicketPaymentLink::factory()->for($ticket)->create();
    $settledTicket = Ticket::factory()->chargeable()->create();
    $settled = TicketPaymentLink::factory()->for($settledTicket)->settled()->create();
    $linkless = Ticket::factory()->chargeable()->create();

    $payments = app(TicketPaymentService::class);
    $payments->cancelForTicket($ticket);
    $payments->cancelForTicket($settledTicket);
    $payments->cancelForTicket($linkless);

    expect($pending->refresh()->status)->toBe(PaymentLinkStatus::Cancelled)
        ->and($settled->refresh()->status)->toBe(PaymentLinkStatus::Settled);
});

it('refuses to assign a ticket that was cancelled after the call was authorized', function (): void {
    $manager = raceTestManager();
    $employee = EmployeeProfile::factory()->create();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Live]);

    runOnceBetweenAuthorizeAndWrite('assign', function () use ($ticket): void {
        Ticket::query()->whereKey($ticket->getKey())->update(['status' => TicketStatus::Cancelled->value]);
    });

    expect(fn () => app(TicketLifecycleService::class)->assign($ticket, $employee, $manager))
        ->toThrow(InvalidStatusTransition::class)
        ->and(TicketAssignment::query()->where('ticket_id', $ticket->getKey())->count())->toBe(0)
        ->and($ticket->refresh()->status)->toBe(TicketStatus::Cancelled)
        ->and($ticket->assigned_employee_id)->toBeNull();
});

it('refuses to unassign a ticket whose assignment moved on after the call was authorized', function (): void {
    $manager = raceTestManager();
    $employee = EmployeeProfile::factory()->create();
    $ticket = Ticket::factory()->create([
        'status' => TicketStatus::Assigned,
        'assigned_employee_id' => $employee->getKey(),
    ]);

    runOnceBetweenAuthorizeAndWrite('assign', function () use ($ticket): void {
        Ticket::query()->whereKey($ticket->getKey())->update(['status' => TicketStatus::InProgress->value]);
    });

    expect(fn () => app(TicketLifecycleService::class)->unassign($ticket, $manager))
        ->toThrow(InvalidStatusTransition::class)
        ->and($ticket->refresh()->status)->toBe(TicketStatus::InProgress)
        ->and($ticket->assigned_employee_id)->toBe($employee->getKey());
});

// ---------------------------------------------------------------------------
// L20 — recovery claims re-read under lock
// ---------------------------------------------------------------------------

function raceRecoveryClaim(WarrantyRecoveryStatus $status, int $received = 0): WarrantyRecoveryClaim
{
    $record = MaintenanceRecord::factory()->create([
        'coverage_decision' => WarrantyClaimDecision::ThirdPartyWarranty,
        'coverage_source' => WarrantyCoverageSource::ManufacturerWarranty,
    ]);

    return WarrantyRecoveryClaim::query()->create([
        'maintenance_record_id' => $record->getKey(),
        'coverage_source' => WarrantyCoverageSource::ManufacturerWarranty,
        'counterparty_name' => 'Acme Manufacturing',
        'status' => $status,
        'currency' => 'AED',
        'claimed_amount_minor' => 10000,
        'approved_amount_minor' => in_array($status, [WarrantyRecoveryStatus::Approved, WarrantyRecoveryStatus::PartiallyReceived], true) ? 10000 : null,
        'received_amount_minor' => $received,
    ]);
}

it('sums two receipts recorded from stale claim instances instead of overwriting the first', function (): void {
    $manager = raceTestManager();
    $claim = raceRecoveryClaim(WarrantyRecoveryStatus::Approved);
    $first = WarrantyRecoveryClaim::query()->findOrFail($claim->getKey());
    $second = WarrantyRecoveryClaim::query()->findOrFail($claim->getKey());
    $service = app(WarrantyRecoveryService::class);

    $service->recordReceipt($first, 6000, $manager);
    $service->recordReceipt($second, 4000, $manager);

    expect($claim->refresh()->received_amount_minor)->toBe(10000)
        ->and($claim->status)->toBe(WarrantyRecoveryStatus::Received);
});

it('refuses a second stale receipt that would exceed the approved amount', function (): void {
    $manager = raceTestManager();
    $claim = raceRecoveryClaim(WarrantyRecoveryStatus::Approved);
    $first = WarrantyRecoveryClaim::query()->findOrFail($claim->getKey());
    $second = WarrantyRecoveryClaim::query()->findOrFail($claim->getKey());
    $service = app(WarrantyRecoveryService::class);

    $service->recordReceipt($first, 7000, $manager);

    expect(fn () => $service->recordReceipt($second, 7000, $manager))
        ->toThrow(ValidationException::class, 'outstanding approved amount');

    expect($claim->refresh()->received_amount_minor)->toBe(7000)
        ->and($claim->status)->toBe(WarrantyRecoveryStatus::PartiallyReceived);
});

it('refuses recording a receipt against a claim whose committed status is no longer approved', function (): void {
    $claim = raceRecoveryClaim(WarrantyRecoveryStatus::Approved);
    $stale = WarrantyRecoveryClaim::query()->findOrFail($claim->getKey());
    $claim->update(['status' => WarrantyRecoveryStatus::Rejected]);

    expect(fn () => app(WarrantyRecoveryService::class)->recordReceipt($stale, 100, raceTestManager()))
        ->toThrow(DomainException::class, 'approved claim');
});

it('refuses to submit, approve or reject a claim that already moved on', function (): void {
    $manager = raceTestManager();
    $service = app(WarrantyRecoveryService::class);

    $draft = raceRecoveryClaim(WarrantyRecoveryStatus::Draft);
    $staleDraft = WarrantyRecoveryClaim::query()->findOrFail($draft->getKey());
    $service->submit($draft, $manager, 'REF-1');

    expect(fn () => $service->submit($staleDraft, $manager, 'REF-2'))
        ->toThrow(DomainException::class, 'draft recovery claim')
        ->and($draft->refresh()->external_reference)->toBe('REF-1');

    $submitted = raceRecoveryClaim(WarrantyRecoveryStatus::Submitted);
    $staleForApprove = WarrantyRecoveryClaim::query()->findOrFail($submitted->getKey());
    $staleForReject = WarrantyRecoveryClaim::query()->findOrFail($submitted->getKey());
    $service->reject($submitted, 'Not covered.', $manager);

    expect(fn () => $service->approve($staleForApprove, 5000, $manager))
        ->toThrow(DomainException::class, 'submitted recovery claim')
        ->and(fn () => $service->reject($staleForReject, 'Again.', $manager))
        ->toThrow(DomainException::class, 'submitted recovery claim')
        ->and($submitted->refresh()->status)->toBe(WarrantyRecoveryStatus::Rejected)
        ->and($submitted->approved_amount_minor)->toBeNull();
});

it('validates approval and rejection input against the locked claim', function (): void {
    $manager = raceTestManager();
    $service = app(WarrantyRecoveryService::class);
    $claim = raceRecoveryClaim(WarrantyRecoveryStatus::Submitted);

    expect(fn () => $service->approve($claim, 10001, $manager))
        ->toThrow(ValidationException::class, 'cannot exceed the claimed amount')
        ->and(fn () => $service->reject($claim, '  ', $manager))
        ->toThrow(ValidationException::class, 'rejection reason');

    $approved = $service->approve($claim, 9000, $manager);

    expect($approved->status)->toBe(WarrantyRecoveryStatus::Approved)
        ->and($approved->approved_amount_minor)->toBe(9000);
});

it('refuses to open a recovery claim for a missing or inactive supplier', function (): void {
    $manager = raceTestManager();
    $record = MaintenanceRecord::factory()->create([
        'coverage_decision' => WarrantyClaimDecision::ThirdPartyWarranty,
        'coverage_source' => WarrantyCoverageSource::SupplierWarranty,
    ]);
    $payload = [
        'currency' => 'AED',
        'claimed_amount_minor' => 5000,
    ];

    expect(fn () => app(WarrantyRecoveryService::class)->create($record, [...$payload, 'supplier_id' => 999999], $manager))
        ->toThrow(ValidationException::class, 'active supplier')
        ->and(fn () => app(WarrantyRecoveryService::class)->create($record, [
            ...$payload,
            'supplier_id' => Supplier::factory()->create(['is_active' => false])->getKey(),
        ], $manager))->toThrow(ValidationException::class, 'active supplier');

    $claim = app(WarrantyRecoveryService::class)->create($record, [
        ...$payload,
        'supplier_id' => Supplier::factory()->create()->getKey(),
    ], $manager);

    expect($claim->status)->toBe(WarrantyRecoveryStatus::Draft);
});

// ---------------------------------------------------------------------------
// L22 — warranty replacement re-checks the original under lock
// ---------------------------------------------------------------------------

/** @return array{0: WarrantyEntitlement, 1: SerializedInventoryUnit} */
function activeEntitlementWithReplacementUnit(string $rule = 'remaining_original_term'): array
{
    $customer = CustomerProfile::factory()->create();
    $original = WarrantyEntitlement::factory()->create([
        'customer_id' => $customer->getKey(),
        'replacement_rule' => $rule,
        'starts_on' => today()->subMonths(2),
        'expires_on' => today()->addMonths(10),
    ]);
    $original->serializedInventoryUnit?->forceFill(['custody_reference_id' => $customer->getKey()])->save();
    $replacementUnit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $customer->getKey(),
    ]);

    return [$original, $replacementUnit];
}

it('carries an active warranty to the replacement unit and ends the original', function (string $rule): void {
    $manager = raceTestManager();
    [$original, $replacementUnit] = activeEntitlementWithReplacementUnit($rule);

    $replacement = app(WarrantyEntitlementService::class)->applyReplacement(
        $original,
        $replacementUnit,
        $manager,
        'Unit swapped under warranty.',
        today()->addMonths(3),
    );

    expect($replacement->state)->toBe(WarrantyEntitlementState::Active)
        ->and($replacement->replacement_of_entitlement_id)->toBe($original->getKey())
        ->and($original->refresh()->state)->toBe(WarrantyEntitlementState::Ended);
})->with(['remaining_original_term', 'restart_full_term', 'manual_review']);

it('refuses a replacement when the original entitlement ended after the stale model was loaded', function (): void {
    $manager = raceTestManager();
    [$original, $replacementUnit] = activeEntitlementWithReplacementUnit();
    $stale = WarrantyEntitlement::query()->findOrFail($original->getKey());

    app(WarrantyEntitlementService::class)->cancel($original, $manager, 'Warranty voided.');

    expect(fn () => app(WarrantyEntitlementService::class)->applyReplacement($stale, $replacementUnit, $manager, 'Late swap.'))
        ->toThrow(ValidationException::class, 'active warranty entitlement')
        ->and(WarrantyEntitlement::query()->where('replacement_of_entitlement_id', $original->getKey())->exists())->toBeFalse()
        ->and($original->refresh()->state)->toBe(WarrantyEntitlementState::Cancelled);
});

it('validates the input and coverage preconditions of a recovery claim', function (): void {
    $manager = raceTestManager();
    $service = app(WarrantyRecoveryService::class);
    $manufacturer = MaintenanceRecord::factory()->create([
        'coverage_decision' => WarrantyClaimDecision::ThirdPartyWarranty,
        'coverage_source' => WarrantyCoverageSource::ManufacturerWarranty,
    ]);
    $valid = ['currency' => 'AED', 'claimed_amount_minor' => 5000, 'counterparty_name' => 'Acme'];

    expect(fn () => $service->create(MaintenanceRecord::factory()->create(), $valid, $manager))
        ->toThrow(ValidationException::class, 'only available for manufacturer or supplier')
        ->and(fn () => $service->create(MaintenanceRecord::factory()->create([
            'coverage_decision' => WarrantyClaimDecision::ThirdPartyWarranty,
            'coverage_source' => WarrantyCoverageSource::Goodwill,
        ]), $valid, $manager))->toThrow(ValidationException::class, 'manufacturer or supplier warranty coverage')
        ->and(fn () => $service->create($manufacturer, [...$valid, 'claimed_amount_minor' => 0], $manager))
        ->toThrow(ValidationException::class, 'greater than zero')
        ->and(fn () => $service->create($manufacturer, [...$valid, 'currency' => 'DIRHAM'], $manager))
        ->toThrow(ValidationException::class, 'three-letter currency')
        ->and(fn () => $service->create($manufacturer, [...$valid, 'counterparty_name' => ' '], $manager))
        ->toThrow(ValidationException::class, 'manufacturer or warranty provider name')
        ->and(fn () => $service->create(MaintenanceRecord::factory()->create([
            'coverage_decision' => WarrantyClaimDecision::ThirdPartyWarranty,
            'coverage_source' => WarrantyCoverageSource::SupplierWarranty,
        ]), $valid, $manager))->toThrow(ValidationException::class, 'supplier responsible');

    $service->create($manufacturer, $valid, $manager);

    expect(fn () => $service->create($manufacturer, $valid, $manager))
        ->toThrow(ValidationException::class, 'already has a third-party recovery claim');
});

it('rejects a replacement that breaks the entitlement, custody or expiry rules', function (): void {
    $manager = raceTestManager();
    $service = app(WarrantyEntitlementService::class);
    [$original, $replacementUnit] = activeEntitlementWithReplacementUnit();
    $foreignUnit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => CustomerProfile::factory()->create()->getKey(),
    ]);
    [$manualReview, $manualUnit] = activeEntitlementWithReplacementUnit('manual_review');
    [$unknownRule, $unknownUnit] = activeEntitlementWithReplacementUnit('mystery_rule');
    $pending = WarrantyEntitlement::factory()->create(['state' => WarrantyEntitlementState::PendingActivation]);

    expect(fn () => $service->applyReplacement($pending, $replacementUnit, $manager, 'Swap.'))
        ->toThrow(ValidationException::class, 'active warranty entitlement')
        ->and(fn () => $service->applyReplacement($original, $replacementUnit, $manager, '  '))
        ->toThrow(ValidationException::class, 'replacement reason')
        ->and(fn () => $service->applyReplacement($original, $foreignUnit, $manager, 'Swap.'))
        ->toThrow(ValidationException::class, 'same customer custody')
        ->and(fn () => $service->applyReplacement($original, $original->serializedInventoryUnit, $manager, 'Swap.'))
        ->toThrow(ValidationException::class, 'different serialized unit')
        ->and(fn () => $service->applyReplacement($manualReview, $manualUnit, $manager, 'Swap.'))
        ->toThrow(ValidationException::class, 'today or later')
        ->and(fn () => $service->applyReplacement($manualReview, $manualUnit, $manager, 'Swap.', today()->subDay()))
        ->toThrow(ValidationException::class, 'today or later')
        ->and(fn () => $service->applyReplacement($unknownRule, $unknownUnit, $manager, 'Swap.'))
        ->toThrow(ValidationException::class, 'Unknown replacement warranty rule');
});
