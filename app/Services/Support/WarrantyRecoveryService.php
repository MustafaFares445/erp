<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\SupportPermission;
use App\Enums\WarrantyClaimDecision;
use App\Enums\WarrantyCoverageSource;
use App\Enums\WarrantyRecoveryStatus;
use App\Models\MaintenanceRecord;
use App\Models\User;
use App\Models\WarrantyRecoveryClaim;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class WarrantyRecoveryService
{
    /** @param array<string, mixed> $data */
    public function create(MaintenanceRecord $record, array $data, User $actor): WarrantyRecoveryClaim
    {
        Gate::forUser($actor)->authorize(SupportPermission::WarrantyRecoveryManage->value);

        if ($record->coverage_decision !== WarrantyClaimDecision::ThirdPartyWarranty) {
            throw ValidationException::withMessages([
                'record' => 'A recovery claim is only available for manufacturer or supplier warranty coverage.',
            ]);
        }

        if ($record->warrantyRecoveryClaim()->exists()) {
            throw ValidationException::withMessages([
                'record' => 'This maintenance request already has a third-party recovery claim.',
            ]);
        }

        $source = $record->coverage_source;
        if (! in_array($source, [
            WarrantyCoverageSource::ManufacturerWarranty,
            WarrantyCoverageSource::SupplierWarranty,
        ], true)) {
            throw ValidationException::withMessages([
                'coverage_source' => 'Recovery requires manufacturer or supplier warranty coverage.',
            ]);
        }

        $amount = $this->positiveMinor($data['claimed_amount_minor'] ?? null, 'claimed_amount_minor');
        $currency = $this->currency($data['currency'] ?? null);
        $supplierId = is_numeric($data['supplier_id'] ?? null) ? (int) $data['supplier_id'] : null;
        $counterparty = $this->nullableText($data['counterparty_name'] ?? null);

        if ($source === WarrantyCoverageSource::SupplierWarranty && $supplierId === null) {
            throw ValidationException::withMessages(['supplier_id' => 'Choose the supplier responsible for this warranty claim.']);
        }

        if ($source === WarrantyCoverageSource::ManufacturerWarranty && $counterparty === null) {
            throw ValidationException::withMessages(['counterparty_name' => 'Enter the manufacturer or warranty provider name.']);
        }

        return DB::transaction(function () use ($record, $actor, $source, $amount, $currency, $supplierId, $counterparty, $data): WarrantyRecoveryClaim {
            $claim = WarrantyRecoveryClaim::query()->create([
                'maintenance_record_id' => $record->getKey(),
                'coverage_source' => $source,
                'supplier_id' => $supplierId,
                'counterparty_name' => $counterparty,
                'external_reference' => $this->nullableText($data['external_reference'] ?? null),
                'status' => WarrantyRecoveryStatus::Draft,
                'currency' => $currency,
                'claimed_amount_minor' => $amount,
                'received_amount_minor' => 0,
                'notes' => $this->nullableText($data['notes'] ?? null),
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->audit($claim, $actor, 'support.warranty_recovery.created');

            return $claim->refresh();
        });
    }

    public function submit(WarrantyRecoveryClaim $claim, User $actor, ?string $reference = null): WarrantyRecoveryClaim
    {
        Gate::forUser($actor)->authorize(SupportPermission::WarrantyRecoveryManage->value);

        if ($claim->status !== WarrantyRecoveryStatus::Draft) {
            throw new DomainException('Only a draft recovery claim can be submitted.');
        }

        $claim->update([
            'status' => WarrantyRecoveryStatus::Submitted,
            'external_reference' => $this->nullableText($reference) ?? $claim->external_reference,
            'submitted_at' => now(),
            'updated_by' => $actor->getKey(),
        ]);
        $this->audit($claim, $actor, 'support.warranty_recovery.submitted');

        return $claim->refresh();
    }

    public function approve(WarrantyRecoveryClaim $claim, int $approvedAmountMinor, User $actor): WarrantyRecoveryClaim
    {
        Gate::forUser($actor)->authorize(SupportPermission::WarrantyRecoveryManage->value);

        if ($claim->status !== WarrantyRecoveryStatus::Submitted) {
            throw new DomainException('Only a submitted recovery claim can be approved.');
        }

        if ($approvedAmountMinor <= 0 || $approvedAmountMinor > $claim->claimed_amount_minor) {
            throw ValidationException::withMessages([
                'approved_amount_minor' => 'Approved amount must be positive and cannot exceed the claimed amount.',
            ]);
        }

        $claim->update([
            'status' => WarrantyRecoveryStatus::Approved,
            'approved_amount_minor' => $approvedAmountMinor,
            'decided_at' => now(),
            'rejection_reason' => null,
            'updated_by' => $actor->getKey(),
        ]);
        $this->audit($claim, $actor, 'support.warranty_recovery.approved');

        return $claim->refresh();
    }

    public function reject(WarrantyRecoveryClaim $claim, string $reason, User $actor): WarrantyRecoveryClaim
    {
        Gate::forUser($actor)->authorize(SupportPermission::WarrantyRecoveryManage->value);

        if ($claim->status !== WarrantyRecoveryStatus::Submitted) {
            throw new DomainException('Only a submitted recovery claim can be rejected.');
        }

        if (mb_trim($reason) === '') {
            throw ValidationException::withMessages(['rejection_reason' => 'A rejection reason is required.']);
        }

        $claim->update([
            'status' => WarrantyRecoveryStatus::Rejected,
            'decided_at' => now(),
            'rejection_reason' => mb_trim($reason),
            'updated_by' => $actor->getKey(),
        ]);
        $this->audit($claim, $actor, 'support.warranty_recovery.rejected');

        return $claim->refresh();
    }

    public function recordReceipt(WarrantyRecoveryClaim $claim, int $amountMinor, User $actor): WarrantyRecoveryClaim
    {
        Gate::forUser($actor)->authorize(SupportPermission::WarrantyRecoveryManage->value);

        if (! in_array($claim->status, [WarrantyRecoveryStatus::Approved, WarrantyRecoveryStatus::PartiallyReceived], true)) {
            throw new DomainException('Recovery can only be recorded against an approved claim.');
        }

        if ($amountMinor <= 0 || $amountMinor > $claim->outstandingMinor()) {
            throw ValidationException::withMessages([
                'received_amount_minor' => 'Received amount must be positive and cannot exceed the outstanding approved amount.',
            ]);
        }

        $received = $claim->received_amount_minor + $amountMinor;
        $approved = $claim->approved_amount_minor ?? 0;
        $status = $received >= $approved ? WarrantyRecoveryStatus::Received : WarrantyRecoveryStatus::PartiallyReceived;

        $claim->update([
            'status' => $status,
            'received_amount_minor' => $received,
            'received_at' => $status === WarrantyRecoveryStatus::Received ? now() : null,
            'updated_by' => $actor->getKey(),
        ]);
        $this->audit($claim, $actor, 'support.warranty_recovery.receipt_recorded');

        return $claim->refresh();
    }

    private function audit(WarrantyRecoveryClaim $claim, User $actor, string $event): void
    {
        activity()
            ->performedOn($claim)
            ->causedBy($actor)
            ->withChanges(['attributes' => $claim->getAttributes()])
            ->withProperties(['source_channel' => 'dashboard'])
            ->log($event);
    }

    private function positiveMinor(mixed $value, string $field): int
    {
        if (! is_numeric($value) || (int) $value <= 0) {
            throw ValidationException::withMessages([$field => 'Amount must be greater than zero.']);
        }

        return (int) $value;
    }

    private function currency(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^[A-Za-z]{3}$/', $value)) {
            throw ValidationException::withMessages(['currency' => 'Choose a valid three-letter currency.']);
        }

        return mb_strtoupper($value);
    }

    private function nullableText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = mb_trim($value);

        return $value === '' ? null : $value;
    }
}
