<?php

declare(strict_types=1);

use App\Enums\WarrantyCoverageSource;
use App\Enums\WarrantyRecoveryStatus;
use App\Filament\Resources\MaintenanceRequests\Actions\WarrantyRecoveryActions;
use App\Models\MaintenanceRecord;
use App\Models\User;
use App\Models\WarrantyRecoveryClaim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

function additionalWarrantyRecoveryAction(string $name): mixed
{
    foreach (WarrantyRecoveryActions::make() as $action) {
        if ($action->getName() === $name) {
            return $action;
        }
    }

    throw new LogicException("Missing recovery action {$name}");
}

/** @return array{MaintenanceRecord, WarrantyRecoveryClaim} */
function additionalWarrantyRecoveryFixture(
    WarrantyRecoveryStatus $status,
    int $claimed = 10000,
    ?int $approved = null,
    int $received = 0,
): array {
    $record = MaintenanceRecord::factory()->create();
    $claim = WarrantyRecoveryClaim::factory()->create([
        'maintenance_record_id' => $record->id,
        'coverage_source' => WarrantyCoverageSource::ManufacturerWarranty,
        'counterparty_name' => 'Additional Coverage Provider',
        'status' => $status,
        'claimed_amount_minor' => $claimed,
        'approved_amount_minor' => $approved,
        'received_amount_minor' => $received,
    ]);

    return [$record, $claim];
}

it('covers warranty recovery action success and handled failure branches', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    [$submitRecord, $submitClaim] = additionalWarrantyRecoveryFixture(WarrantyRecoveryStatus::Draft);
    $submit = additionalWarrantyRecoveryAction('submitWarrantyRecovery')->getActionFunction();
    $submit($submitRecord, ['external_reference' => 'EXT-ADD-COV']);
    expect($submitClaim->refresh()->status)->toBe(WarrantyRecoveryStatus::Submitted);

    $submit($submitRecord->refresh(), ['external_reference' => 'DUPLICATE']);
    expect($submitClaim->refresh()->status)->toBe(WarrantyRecoveryStatus::Submitted);

    [$decisionRecord, $decisionClaim] = additionalWarrantyRecoveryFixture(WarrantyRecoveryStatus::Submitted);
    $decision = additionalWarrantyRecoveryAction('decideWarrantyRecovery')->getActionFunction();
    $decision($decisionRecord, [
        'decision' => 'rejected',
        'rejection_reason' => 'Provider declined the claim',
    ]);
    expect($decisionClaim->refresh()->status)->toBe(WarrantyRecoveryStatus::Rejected)
        ->and($decisionClaim->rejection_reason)->toBe('Provider declined the claim');

    $decision($decisionRecord->refresh(), [
        'decision' => 'rejected',
        'rejection_reason' => 'Repeated decision',
    ]);
    expect($decisionClaim->refresh()->status)->toBe(WarrantyRecoveryStatus::Rejected);

    [$receiptRecord, $receiptClaim] = additionalWarrantyRecoveryFixture(
        WarrantyRecoveryStatus::Approved,
        claimed: 10000,
        approved: 5000,
    );
    $receipt = additionalWarrantyRecoveryAction('recordWarrantyRecoveryReceipt')->getActionFunction();
    $receipt($receiptRecord, ['received_amount' => '60.00']);

    expect($receiptClaim->refresh()->status)->toBe(WarrantyRecoveryStatus::Approved)
        ->and($receiptClaim->received_amount_minor)->toBe(0);
});

it('covers unauthenticated warranty recovery actor guard', function (): void {
    auth()->logout();

    expect(fn () => new ReflectionMethod(WarrantyRecoveryActions::class, 'actor')->invoke(null))
        ->toThrow(LogicException::class, 'authenticated User');
});
