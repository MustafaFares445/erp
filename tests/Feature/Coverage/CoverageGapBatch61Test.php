<?php

declare(strict_types=1);

use App\Enums\MaintenanceStatus;
use App\Enums\SerializedCustodyType;
use App\Enums\WarrantyClaimDecision;
use App\Enums\WarrantyCoverageSource;
use App\Enums\WarrantyDurationUnit;
use App\Enums\WarrantyEntitlementState;
use App\Enums\WarrantyLineCategory;
use App\Enums\WarrantyStartTrigger;
use App\Enums\WarrantyStatus;
use App\Filament\Resources\MaintenanceRequests\Actions\WarrantyClaimActions;
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
use App\Services\Support\WarrantyEntitlementService;
use App\Services\Support\WarrantyResolver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

function invokeWarrantyClaim(string $method, mixed ...$args): mixed
{
    return new ReflectionMethod(WarrantyClaimService::class, $method)
        ->invoke(app(WarrantyClaimService::class), ...$args);
}

function invokeWarrantyEntitlement(string $method, mixed ...$args): mixed
{
    return new ReflectionMethod(WarrantyEntitlementService::class, $method)
        ->invoke(app(WarrantyEntitlementService::class), ...$args);
}

function invokeWarrantyResolver(string $method, mixed ...$args): mixed
{
    return new ReflectionMethod(WarrantyResolver::class, $method)
        ->invoke(app(WarrantyResolver::class), ...$args);
}

function invokeWarrantyActionHelper(string $method, mixed ...$args): mixed
{
    return new ReflectionMethod(WarrantyClaimActions::class, $method)
        ->invoke(null, ...$args);
}

it('suggests warranty claim lines across reversed missing covered and uncovered job costs', function (): void {
    $record = MaintenanceRecord::factory()->create([
        'warranty_status' => WarrantyStatus::Covered,
        'warranty_expiry_date' => today()->addMonth(),
    ]);
    $customer = $record->customer;
    $customer?->setRelation('user', null);
    $record->setRelation('customer', $customer);
    $record->setRelation('serializedInventoryUnit', null);

    $variant = new ProductVariant;
    $variant->forceFill([
        'id' => 901,
        'name' => '',
        'sku' => 'SKU-WARRANTY',
        'base_price' => '50.00',
        'min_price' => null,
    ]);

    $validPart = new ServiceRecordPart;
    $validPart->forceFill(['id' => 101, 'quantity' => '2.000000', 'reversed_at' => null]);
    $validPart->setRelation('productVariant', $variant);

    $reversed = new ServiceRecordPart;
    $reversed->forceFill(['id' => 102, 'quantity' => '1.000000', 'reversed_at' => now()]);
    $reversed->setRelation('productVariant', $variant);

    $missingVariant = new ServiceRecordPart;
    $missingVariant->forceFill(['id' => 103, 'quantity' => '1.000000', 'reversed_at' => null]);
    $missingVariant->setRelation('productVariant', null);

    $task = new MaintenanceTask;
    $task->setRelation('parts', new EloquentCollection([$reversed, $missingVariant, $validPart]));

    $record->setRelation('serviceRecords', new EloquentCollection([$task]));

    $labour = new MaintenanceLabourEntry;
    $labour->forceFill(['total_cost_minor' => 5000]);

    $record->setRelation('labourEntries', new EloquentCollection([$labour]));

    $thirdParty = new MaintenanceThirdPartyCost;
    $thirdParty->forceFill(['id' => 201, 'description' => 'External calibration', 'amount_minor' => 3000]);

    $record->setRelation('thirdPartyCosts', new EloquentCollection([$thirdParty]));

    $lines = app(WarrantyClaimService::class)->suggestedCoverageLines($record);

    expect($lines)->toHaveCount(3)
        ->and($lines[0]['description'])->toBe('SKU-WARRANTY')
        ->and($lines[0]['coverage_percent'])->toBe(100.0)
        ->and($lines[1]['coverage_percent'])->toBe(100.0)
        ->and($lines[2]['coverage_percent'])->toBe(0.0);
});

it('uses serialized warranty entitlement flags when suggesting claim lines', function (): void {
    $record = MaintenanceRecord::factory()->create(['warranty_status' => WarrantyStatus::Unknown]);
    $customer = $record->customer;
    $record->setRelation('customer', $customer);

    $unit = new SerializedInventoryUnit;
    $unit->forceFill(['id' => 777]);

    $entitlement = new WarrantyEntitlement;
    $entitlement->forceFill([
        'id' => 55,
        'customer_id' => $record->customer_id,
        'covers_parts' => false,
        'covers_labour' => false,
        'covers_third_party' => true,
    ]);
    $unit->setRelation('warrantyEntitlements', new EloquentCollection([$entitlement]));
    $record->setRelation('serializedInventoryUnit', $unit);

    $variant = new ProductVariant;
    $variant->forceFill(['id' => 902, 'name' => 'Covered item', 'sku' => 'SKU-902', 'base_price' => '20.00']);

    $part = new ServiceRecordPart;
    $part->forceFill(['id' => 202, 'quantity' => '1.000000', 'reversed_at' => null]);
    $part->setRelation('productVariant', $variant);

    $task = new MaintenanceTask;
    $task->setRelation('parts', new EloquentCollection([$part]));

    $record->setRelation('serviceRecords', new EloquentCollection([$task]));

    $labour = new MaintenanceLabourEntry;
    $labour->forceFill(['total_cost_minor' => 1000]);

    $record->setRelation('labourEntries', new EloquentCollection([$labour]));

    $thirdParty = new MaintenanceThirdPartyCost;
    $thirdParty->forceFill(['id' => 203, 'description' => 'Supplier repair', 'amount_minor' => 2000]);

    $record->setRelation('thirdPartyCosts', new EloquentCollection([$thirdParty]));

    $lines = app(WarrantyClaimService::class)->suggestedCoverageLines($record);

    expect($lines)->toHaveCount(3)
        ->and($lines[0]['coverage_percent'])->toBe(0.0)
        ->and($lines[1]['coverage_percent'])->toBe(0.0)
        ->and($lines[2]['coverage_percent'])->toBe(100.0);
});

it('covers warranty claim decision validation line persistence and source helpers', function (): void {
    $actor = User::factory()->admin()->create();
    $record = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Diagnosing,
        'diagnosed_at' => now(),
        'warranty_status' => WarrantyStatus::Covered,
        'warranty_expiry_date' => today()->addMonth(),
    ]);

    expect(fn () => app(WarrantyClaimService::class)->decideCoverage($record, [
        'coverage_decision' => WarrantyClaimDecision::PendingDiagnosis->value,
        'coverage_reason' => 'Pending',
    ], $actor))->toThrow(ValidationException::class, 'Choose a final coverage decision');

    expect(fn () => app(WarrantyClaimService::class)->decideCoverage($record, [
        'coverage_decision' => WarrantyClaimDecision::Rejected->value,
        'coverage_reason' => 'Rejected',
    ], $actor))->toThrow(ValidationException::class, 'Explain the customer responsibility');

    $result = app(WarrantyClaimService::class)->decideCoverage($record, [
        'coverage_decision' => WarrantyClaimDecision::PartiallyCovered->value,
        'coverage_reason' => 'Partial',
        'customer_coverage_explanation' => 'Customer pays half.',
        'coverage_lines' => [
            'skip-me',
            [
                'category' => WarrantyLineCategory::Labour->value,
                'description' => 'Labour',
                'amount_minor' => 10000,
                'coverage_percent' => 50,
                'coverage_source' => WarrantyCoverageSource::SellerWarranty->value,
            ],
        ],
    ], $actor);

    expect($result->coverage_decision)->toBe(WarrantyClaimDecision::PartiallyCovered)
        ->and($result->status)->toBe(MaintenanceStatus::AwaitingApproval)
        ->and($result->coverageLines()->count())->toBe(1);

    expect(fn (): mixed => invokeWarrantyClaim(
        'persistCoverageLine',
        $record->refresh(),
        [
            'category' => WarrantyLineCategory::Other->value,
            'description' => 'Invalid amount',
            'amount_minor' => -1,
        ],
        WarrantyClaimDecision::Rejected,
        WarrantyCoverageSource::CustomerPaid,
        $actor,
    ))->toThrow(ValidationException::class, 'non-negative amount');

    invokeWarrantyClaim(
        'persistCoverageLine',
        $record->refresh(),
        [
            'category' => WarrantyLineCategory::Other->value,
            'description' => 'Pending direct coverage branch',
            'amount_minor' => 100,
        ],
        WarrantyClaimDecision::PendingDiagnosis,
        null,
        $actor,
    );

    expect(invokeWarrantyClaim(
        'thirdPartySource',
        WarrantyCoverageSource::ManufacturerWarranty->value,
    ))->toBe(WarrantyCoverageSource::ManufacturerWarranty)
        ->and(invokeWarrantyClaim('lineCoverageSource', null, 0.0, null))->toBe(WarrantyCoverageSource::CustomerPaid)
        ->and(invokeWarrantyClaim('lineCoverageSource', null, 50.0, null))->toBe(WarrantyCoverageSource::SellerWarranty)
        ->and(invokeWarrantyClaim('coveragePercent', '33.333'))->toBe(33.33);
});

it('activates warranty entitlements and covers stale and validation guards', function (): void {
    $actor = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $customer->id,
    ]);

    $pending = WarrantyEntitlement::factory()->create([
        'serialized_inventory_unit_id' => $unit->id,
        'customer_id' => $customer->id,
        'state' => WarrantyEntitlementState::PendingActivation,
        'duration_value' => 2,
        'duration_unit' => WarrantyDurationUnit::Months,
        'starts_on' => null,
        'expires_on' => null,
    ]);

    expect(fn () => app(WarrantyEntitlementService::class)->activate(
        $pending,
        today(),
        $actor,
        '   ',
    ))->toThrow(DomainException::class, 'activation reason');

    $active = WarrantyEntitlement::factory()->create(['state' => WarrantyEntitlementState::Active]);
    expect(fn () => app(WarrantyEntitlementService::class)->activate(
        $active,
        today(),
        $actor,
        'Activate',
    ))->toThrow(DomainException::class, 'pending warranty entitlement');

    $stale = $pending->fresh();
    $pending->forceFill(['state' => WarrantyEntitlementState::Active])->save();
    expect(fn () => app(WarrantyEntitlementService::class)->activate(
        $stale,
        today(),
        $actor,
        'Concurrent activation',
    ))->toThrow(DomainException::class, 'already been processed');

    $freshPending = WarrantyEntitlement::factory()->create([
        'serialized_inventory_unit_id' => $unit->id,
        'customer_id' => $customer->id,
        'state' => WarrantyEntitlementState::PendingActivation,
        'duration_value' => 2,
        'duration_unit' => WarrantyDurationUnit::Months,
        'starts_on' => null,
        'expires_on' => null,
    ]);

    $activated = app(WarrantyEntitlementService::class)->activate(
        $freshPending,
        today(),
        $actor,
        'Customer delivery confirmed.',
    );

    expect($activated->state)->toBe(WarrantyEntitlementState::Active)
        ->and($activated->starts_on)->not->toBeNull()
        ->and($activated->expires_on)->not->toBeNull()
        ->and($unit->refresh()->warranty_started_on)->not->toBeNull();
});

it('rejects an unsaved replacement serial and covers entitlement expiry variants', function (): void {
    $actor = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();
    $originalUnit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $customer->id,
    ]);
    $original = WarrantyEntitlement::factory()->create([
        'serialized_inventory_unit_id' => $originalUnit->id,
        'customer_id' => $customer->id,
        'state' => WarrantyEntitlementState::Active,
        'expires_on' => today()->addMonth(),
    ]);

    $replacement = new SerializedInventoryUnit;
    $replacement->forceFill([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $customer->id,
    ]);

    expect(fn () => app(WarrantyEntitlementService::class)->applyReplacement(
        $original,
        $replacement,
        $actor,
        'Replace failed serial.',
    ))->toThrow(ValidationException::class, 'invalid identifier');

    $start = Carbon::parse('2026-01-31');
    expect(invokeWarrantyEntitlement('expiry', $start, 2, WarrantyDurationUnit::Days)->toDateString())->toBe('2026-02-02')
        ->and(invokeWarrantyEntitlement('expiry', $start, 1, WarrantyDurationUnit::Months)->toDateString())->toBe('2026-02-28')
        ->and(invokeWarrantyEntitlement('expiry', $start, 1, WarrantyDurationUnit::Years)->toDateString())->toBe('2027-01-31');
});

it('covers warranty resolver entitlement states and numeric key guard', function (): void {
    $resolver = app(WarrantyResolver::class);
    $customer = CustomerProfile::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $customer->id,
    ]);
    WarrantyEntitlement::factory()->create([
        'serialized_inventory_unit_id' => $unit->id,
        'customer_id' => $customer->id,
        'state' => WarrantyEntitlementState::PendingActivation,
        'starts_on' => null,
        'expires_on' => null,
        'start_trigger' => WarrantyStartTrigger::Installation,
    ]);

    expect($resolver->resolveForSerializedUnit($unit->refresh(), $customer)->status)->toBe(WarrantyStatus::Unknown);

    $incomplete = new WarrantyEntitlement;
    $incomplete->forceFill([
        'state' => WarrantyEntitlementState::Active,
        'starts_on' => today(),
        'expires_on' => null,
        'start_trigger' => WarrantyStartTrigger::ConfirmedDelivery,
    ]);
    expect(invokeWarrantyResolver('fromEntitlement', $incomplete, $unit->id, now())->status)->toBe(WarrantyStatus::Unknown);

    $ended = new WarrantyEntitlement;
    $ended->forceFill([
        'state' => WarrantyEntitlementState::Ended,
        'starts_on' => today()->subMonth(),
        'expires_on' => today()->addMonth(),
        'end_reason' => '',
        'start_trigger' => WarrantyStartTrigger::ConfirmedDelivery,
    ]);
    expect(invokeWarrantyResolver('fromEntitlement', $ended, $unit->id, now())->status)->toBe(WarrantyStatus::Expired);

    $active = new WarrantyEntitlement;
    $active->forceFill([
        'state' => WarrantyEntitlementState::Active,
        'starts_on' => today()->subDay(),
        'expires_on' => today()->addDay(),
        'start_trigger' => WarrantyStartTrigger::ConfirmedDelivery,
    ]);
    expect(invokeWarrantyResolver('fromEntitlement', $active, $unit->id, now())->status)->toBe(WarrantyStatus::Covered)
        ->and(invokeWarrantyResolver('fromEntitlement', $active, $unit->id, now()->addYear())->status)->toBe(WarrantyStatus::Expired);

    expect(fn (): mixed => invokeWarrantyResolver('integerKey', new SerializedInventoryUnit))
        ->toThrow(LogicException::class, 'numeric identifiers');
});

it('covers warranty claim filament helpers and partially-covered action transformation', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    expect(invokeWarrantyActionHelper('coverageLineSummary', null))->toBe('No coverage lines yet.')
        ->and(invokeWarrantyActionHelper('coverageLineSummary', [
            'skip',
            ['amount' => '100', 'coverage_percent' => '150'],
            ['amount' => 'bad', 'coverage_percent' => 'bad'],
        ]))->toContain('Repair amount 100.00')
        ->and(invokeWarrantyActionHelper('stringKeyedData', [0 => 'skip', 'keep' => 1]))->toBe(['keep' => 1])
        ->and(invokeWarrantyActionHelper('currentActor'))->toBe($actor);

    auth()->logout();
    expect(fn (): mixed => invokeWarrantyActionHelper('currentActor'))
        ->toThrow(LogicException::class, 'authenticated user');

    $this->actingAs($actor);
    $record = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Diagnosing,
        'diagnosed_at' => now(),
        'warranty_status' => WarrantyStatus::Covered,
        'warranty_expiry_date' => today()->addMonth(),
    ]);

    $action = collect(WarrantyClaimActions::make())
        ->first(fn ($candidate): bool => $candidate->getName() === 'determineCoverage');

    expect($action)->not->toBeNull();

    ($action->getActionFunction())($record, [
        'coverage_decision' => WarrantyClaimDecision::PartiallyCovered->value,
        'coverage_reason' => 'Partial repair coverage.',
        'customer_coverage_explanation' => 'Customer pays balance.',
        'coverage_lines' => [
            'skip',
            [
                'category' => WarrantyLineCategory::Labour->value,
                'description' => 'Labour',
                'amount' => '25.50',
                'coverage_percent' => 50,
            ],
            [
                'category' => WarrantyLineCategory::Other->value,
                'description' => 'Invalid numeric amount becomes zero',
                'amount' => 'not-numeric',
                'coverage_percent' => 25,
            ],
        ],
    ]);

    expect($record->refresh()->coverage_decision)->toBe(WarrantyClaimDecision::PartiallyCovered)
        ->and($record->coverageLines()->count())->toBe(2);
});
