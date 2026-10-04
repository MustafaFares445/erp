<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\CalibrationMeasurementResult;
use App\Enums\CalibrationResult;
use App\Enums\MaintenanceIntervalType;
use App\Enums\MaintenanceKind;
use App\Enums\MaintenanceStatus;
use App\Enums\NotificationEventKey;
use App\Enums\SerializedCustodyType;
use App\Events\EquipmentCalibrationMilestone;
use App\Models\EquipmentCalibration;
use App\Models\EquipmentCalibrationMeasurement;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceSchedule;
use App\Models\MaintenanceScheduleOccurrence;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Orchestrates calibration and validation of a customer's serialized unit.
 * Support records the measurement facts, the result, the certificate and the
 * next due date only; it never touches custody, stock or accounting. The next
 * calibration cycle itself is owned by the existing maintenance schedule.
 */
final readonly class EquipmentCalibrationService
{
    public function __construct(private MaintenanceRecordService $records) {}

    /**
     * @param  array{
     *     measurements?: list<array<array-key, mixed>>,
     *     external_provider_id?: int|null,
     *     standard_reference?: string|null,
     *     instrument_reference?: string|null,
     *     notes?: string|null
     * }  $data
     */
    public function start(MaintenanceRecord $record, User $actor, array $data = []): EquipmentCalibration
    {
        Gate::forUser($actor)->authorize('create', EquipmentCalibration::class);

        $measurements = $this->normaliseMeasurements($data['measurements'] ?? []);

        return DB::transaction(function () use ($record, $actor, $data, $measurements): EquipmentCalibration {
            $locked = MaintenanceRecord::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->maintenance_kind !== MaintenanceKind::Calibration) {
                throw ValidationException::withMessages(['maintenance_record_id' => 'Only a calibration maintenance request can carry a calibration.']);
            }

            if ($locked->isFinalised()) {
                throw ValidationException::withMessages(['maintenance_record_id' => 'A closed or cancelled maintenance request cannot start a calibration.']);
            }

            if ($locked->serialized_inventory_unit_id === null) {
                throw ValidationException::withMessages(['serialized_inventory_unit_id' => 'The maintenance request must reference serialized equipment.']);
            }

            $unit = SerializedInventoryUnit::query()->findOrFail($locked->serialized_inventory_unit_id);
            $this->assertUnitBelongsToCustomer($unit, (int) $locked->customer_id);

            if (EquipmentCalibration::query()->where('maintenance_record_id', $locked->getKey())->exists()
                || $this->hasOpenCalibration($unit, $locked)) {
                throw ValidationException::withMessages(['serialized_inventory_unit_id' => 'An open calibration already exists for this equipment.']);
            }

            $calibration = EquipmentCalibration::query()->create([
                'maintenance_record_id' => $locked->getKey(),
                'serialized_inventory_unit_id' => $unit->getKey(),
                'performed_by_employee_id' => $actor->employeeProfile?->getKey(),
                'external_provider_id' => $data['external_provider_id'] ?? null,
                'started_at' => now(),
                'standard_reference' => $data['standard_reference'] ?? null,
                'instrument_reference' => $data['instrument_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($measurements as $index => $measurement) {
                $calibration->measurements()->create([...$measurement, 'sort_order' => $index]);
            }

            $this->log($calibration, $actor, 'support.calibration.started');

            return $calibration;
        });
    }

    public function recordMeasurement(
        EquipmentCalibration $calibration,
        string $measurementKey,
        string $actualValue,
        User $actor,
        ?string $notes = null,
    ): EquipmentCalibrationMeasurement {
        Gate::forUser($actor)->authorize('update', $calibration);

        $actual = $this->decimal($actualValue) ?? throw ValidationException::withMessages(['actual_value' => 'The measured value must be a number.']);

        return DB::transaction(function () use ($calibration, $measurementKey, $actual, $notes): EquipmentCalibrationMeasurement {
            $locked = $this->lockedOpen($calibration);

            $measurement = $locked->measurements()->where('measurement_key', $measurementKey)->first();

            if (! $measurement instanceof EquipmentCalibrationMeasurement) {
                throw ValidationException::withMessages(['measurement_key' => 'Unknown calibration measurement.']);
            }

            $measurement->update([
                'actual_value' => $actual,
                'result' => $this->evaluate($measurement, $actual),
                'notes' => $notes,
            ]);

            return $measurement;
        });
    }

    public function complete(
        EquipmentCalibration $calibration,
        User $actor,
        CalibrationResult $result = CalibrationResult::Passed,
        ?CarbonInterface $calibratedAt = null,
        ?CarbonInterface $nextDueOn = null,
    ): EquipmentCalibration {
        Gate::forUser($actor)->authorize('complete', $calibration);

        if (! $result->isSuccessful()) {
            throw ValidationException::withMessages(['result' => 'A failed calibration must be recorded with a failure reason.']);
        }

        return DB::transaction(function () use ($calibration, $actor, $result, $calibratedAt, $nextDueOn): EquipmentCalibration {
            $locked = $this->lockedOpen($calibration);
            $record = $this->assertEquipmentMatchesRequest($locked);

            $measurements = $locked->measurements()->get();

            if ($measurements->isEmpty()) {
                throw ValidationException::withMessages(['measurements' => 'A calibration needs at least one measurement.']);
            }

            $missing = $measurements->first(static fn (EquipmentCalibrationMeasurement $measurement): bool => $measurement->is_required && ! $measurement->isRecorded());

            if ($missing instanceof EquipmentCalibrationMeasurement) {
                throw ValidationException::withMessages(['measurements' => 'Every required measurement must be recorded before the calibration can be completed.']);
            }

            $failed = $measurements->first(static fn (EquipmentCalibrationMeasurement $measurement): bool => $measurement->is_required && $measurement->result === CalibrationMeasurementResult::Failed);

            if ($failed instanceof EquipmentCalibrationMeasurement) {
                throw ValidationException::withMessages(['measurements' => 'A mandatory measurement is out of tolerance; the calibration cannot pass.']);
            }

            $calibrated = Carbon::instance($calibratedAt ?? now());
            $nextDue = $nextDueOn instanceof CarbonInterface ? Carbon::instance($nextDueOn) : $this->deriveNextDueOn($locked, $record, $calibrated);

            if ($nextDue instanceof Carbon && $nextDue->lte($calibrated)) {
                throw ValidationException::withMessages(['next_calibration_due_on' => 'The next calibration due date must be after the calibration date.']);
            }

            $locked->update([
                'result' => $result,
                'calibrated_at' => $calibrated,
                'failure_reason' => null,
                'next_calibration_due_on' => $nextDue?->toDateString(),
                'performed_by_employee_id' => $locked->performed_by_employee_id ?? $actor->employeeProfile?->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->log($locked, $actor, 'support.calibration.completed', ['result' => $result->value]);
            $this->notify($record, NotificationEventKey::CalibrationCompleted);

            return $locked;
        });
    }

    public function fail(EquipmentCalibration $calibration, User $actor, string $reason, bool $raiseFollowUp = false): EquipmentCalibration
    {
        Gate::forUser($actor)->authorize('complete', $calibration);

        if (mb_trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required when a calibration fails.']);
        }

        if ($raiseFollowUp && ! Gate::forUser($actor)->allows('create', MaintenanceRecord::class)) {
            throw ValidationException::withMessages(['raise_follow_up' => 'You are not allowed to raise a follow-up repair request.']);
        }

        return DB::transaction(function () use ($calibration, $actor, $reason, $raiseFollowUp): EquipmentCalibration {
            $locked = $this->lockedOpen($calibration);
            $record = $this->assertEquipmentMatchesRequest($locked);

            $followUp = $raiseFollowUp ? $this->records->createStandalone([
                'customer_id' => $record->customer_id,
                'serialized_inventory_unit_id' => $record->serialized_inventory_unit_id,
                'description' => sprintf('Follow-up to failed calibration #%d: %s', $record->id, $reason),
                'maintenance_kind' => MaintenanceKind::Corrective,
            ], $actor) : null;

            $locked->update([
                'result' => CalibrationResult::Failed,
                'calibrated_at' => now(),
                'failure_reason' => $reason,
                'next_calibration_due_on' => null,
                'follow_up_maintenance_record_id' => $followUp?->getKey(),
                'performed_by_employee_id' => $locked->performed_by_employee_id ?? $actor->employeeProfile?->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->log($locked, $actor, 'support.calibration.failed', ['reason' => $reason, 'follow_up_maintenance_record_id' => $followUp?->getKey()]);
            $this->notify($record, NotificationEventKey::CalibrationFailed);

            return $locked;
        });
    }

    public function issueCertificate(
        EquipmentCalibration $calibration,
        User $actor,
        string $certificateNumber,
        ?CarbonInterface $expiresOn = null,
    ): EquipmentCalibration {
        Gate::forUser($actor)->authorize('complete', $calibration);

        if (mb_trim($certificateNumber) === '') {
            throw ValidationException::withMessages(['certificate_number' => 'The certificate number is required.']);
        }

        return DB::transaction(function () use ($calibration, $actor, $certificateNumber, $expiresOn): EquipmentCalibration {
            $locked = $this->lockedMutable($calibration);

            if (! $locked->result instanceof CalibrationResult || ! $locked->result->isSuccessful()) {
                throw ValidationException::withMessages(['certificate_number' => 'A certificate can only be issued for a passed calibration.']);
            }

            if ($expiresOn instanceof CarbonInterface && $locked->calibrated_at instanceof CarbonInterface && $expiresOn->lte($locked->calibrated_at)) {
                throw ValidationException::withMessages(['certificate_expires_on' => 'The certificate expiry must be after the calibration date.']);
            }

            $previous = $locked->certificate_number;

            $locked->update([
                'certificate_number' => $certificateNumber,
                'certificate_expires_on' => $expiresOn?->toDateString(),
                'certificate_issued_at' => now(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->log($locked, $actor, 'support.calibration.certificate_issued', [
                'certificate_number' => $certificateNumber,
                'replaced_certificate_number' => $previous,
            ]);

            return $locked;
        });
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return list<array<string, mixed>>
     */
    private function normaliseMeasurements(array $rows): array
    {
        if ($rows === []) {
            throw ValidationException::withMessages(['measurements' => 'A calibration needs at least one measurement.']);
        }

        $normalised = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $label = is_string($row['label'] ?? null) ? mb_trim($row['label']) : '';

            if ($label === '') {
                throw ValidationException::withMessages(['measurements' => 'Every measurement needs a label.']);
            }

            $key = is_string($row['key'] ?? null) && $row['key'] !== '' ? $row['key'] : Str::slug($label, '_');

            if (collect($normalised)->contains('measurement_key', $key)) {
                throw ValidationException::withMessages(['measurements' => 'Measurement keys must be unique.']);
            }

            $minimum = $this->decimal($row['minimum_value'] ?? null);
            $maximum = $this->decimal($row['maximum_value'] ?? null);

            if ($minimum === null && $maximum === null) {
                throw ValidationException::withMessages(['measurements' => 'Each measurement needs a minimum or maximum limit.']);
            }

            if ($minimum !== null && $maximum !== null && BigDecimal::of($minimum)->isGreaterThan($maximum)) {
                throw ValidationException::withMessages(['measurements' => 'A measurement minimum cannot exceed its maximum.']);
            }

            $normalised[] = [
                'measurement_key' => $key,
                'label' => $label,
                'expected_value' => $this->decimal($row['expected_value'] ?? null),
                'minimum_value' => $minimum,
                'maximum_value' => $maximum,
                'unit' => is_string($row['unit'] ?? null) && $row['unit'] !== '' ? $row['unit'] : null,
                'is_required' => (bool) ($row['is_required'] ?? true),
                'result' => CalibrationMeasurementResult::Pending,
            ];
        }

        return $normalised;
    }

    /** Normalises a numeric input to the stored four-decimal string, or null when it is not a number. */
    private function decimal(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        try {
            return BigDecimal::of((string) $value)->toScale(4, RoundingMode::HalfUp)->__toString();
        } catch (MathException) {
            return null;
        }
    }

    private function evaluate(EquipmentCalibrationMeasurement $measurement, string $actual): CalibrationMeasurementResult
    {
        $value = BigDecimal::of($actual);
        $belowMinimum = $measurement->minimum_value !== null && $value->isLessThan($measurement->minimum_value);
        $aboveMaximum = $measurement->maximum_value !== null && $value->isGreaterThan($measurement->maximum_value);

        return $belowMinimum || $aboveMaximum ? CalibrationMeasurementResult::Failed : CalibrationMeasurementResult::Passed;
    }

    /**
     * The next due date follows the equipment's calibration schedule: the one
     * that raised this request, otherwise the unit's active calibration
     * schedule. Without a schedule (or a usage-hours one) it stays unset.
     */
    private function deriveNextDueOn(EquipmentCalibration $calibration, MaintenanceRecord $record, Carbon $calibratedAt): ?Carbon
    {
        $occurrence = MaintenanceScheduleOccurrence::query()
            ->where('maintenance_record_id', $record->getKey())
            ->with('schedule')
            ->first();

        $schedule = $occurrence->schedule ?? MaintenanceSchedule::query()
            ->where('serialized_inventory_unit_id', $calibration->serialized_inventory_unit_id)
            ->where('maintenance_kind', MaintenanceKind::Calibration->value)
            ->where('is_active', true)
            ->latest('id')
            ->first();

        if (! $schedule instanceof MaintenanceSchedule || $schedule->interval_type === MaintenanceIntervalType::UsageHours) {
            return null;
        }

        return $schedule->interval_type->advance($calibratedAt->copy()->startOfDay(), $schedule->interval_value);
    }

    private function lockedMutable(EquipmentCalibration $calibration): EquipmentCalibration
    {
        $locked = EquipmentCalibration::query()->whereKey($calibration->getKey())->lockForUpdate()->firstOrFail();
        $record = MaintenanceRecord::query()->findOrFail($locked->maintenance_record_id);

        if ($record->isFinalised()) {
            throw ValidationException::withMessages(['maintenance_record_id' => 'The maintenance request is closed or cancelled.']);
        }

        return $locked;
    }

    private function lockedOpen(EquipmentCalibration $calibration): EquipmentCalibration
    {
        $locked = $this->lockedMutable($calibration);

        if ($locked->isFinished()) {
            throw ValidationException::withMessages(['result' => 'This calibration has already been recorded.']);
        }

        return $locked;
    }

    private function assertEquipmentMatchesRequest(EquipmentCalibration $calibration): MaintenanceRecord
    {
        $record = MaintenanceRecord::query()->findOrFail($calibration->maintenance_record_id);

        if ($record->serialized_inventory_unit_id !== $calibration->serialized_inventory_unit_id) {
            throw ValidationException::withMessages(['serialized_inventory_unit_id' => 'The calibrated equipment does not match the maintenance request.']);
        }

        $this->assertUnitBelongsToCustomer(
            SerializedInventoryUnit::query()->findOrFail($calibration->serialized_inventory_unit_id),
            (int) $record->customer_id,
        );

        return $record;
    }

    private function assertUnitBelongsToCustomer(SerializedInventoryUnit $unit, int $customerId): void
    {
        if ($unit->custody_type !== SerializedCustodyType::Customer || (int) $unit->custody_reference_id !== $customerId) {
            throw ValidationException::withMessages(['serialized_inventory_unit_id' => 'The equipment is not in the custody of the maintenance request customer.']);
        }
    }

    private function hasOpenCalibration(SerializedInventoryUnit $unit, MaintenanceRecord $record): bool
    {
        return EquipmentCalibration::query()
            ->where('serialized_inventory_unit_id', $unit->getKey())
            ->where('maintenance_record_id', '!=', $record->getKey())
            ->whereNull('result')
            ->whereHas('maintenanceRecord', static fn (Builder $query): Builder => $query->where('status', '!=', MaintenanceStatus::Cancelled->value))
            ->exists();
    }

    private function notify(MaintenanceRecord $record, NotificationEventKey $key): void
    {
        DB::afterCommit(static fn () => EquipmentCalibrationMilestone::dispatch($record, $key));
    }

    /** @param array<string, mixed> $properties */
    private function log(EquipmentCalibration $calibration, User $actor, string $event, array $properties = []): void
    {
        activity()
            ->performedOn($calibration)
            ->causedBy($actor)
            ->withProperties([
                'source_channel' => 'dashboard',
                'maintenance_record_id' => $calibration->maintenance_record_id,
                'serialized_inventory_unit_id' => $calibration->serialized_inventory_unit_id,
                ...$properties,
            ])
            ->log($event);
    }
}
