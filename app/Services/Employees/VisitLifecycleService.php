<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Enums\VisitOutcome;
use App\Enums\VisitStatus;
use App\Models\CustomerVisit;
use App\Services\Employees\Exceptions\InvalidStatusTransition;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final readonly class VisitLifecycleService
{
    public function __construct(private FollowUpCreationService $followUps) {}

    public function markEnRoute(CustomerVisit $visit): CustomerVisit
    {
        return $this->transition($visit, VisitStatus::EnRoute, ['en_route_at' => now()], 'visit.en_route');
    }

    public function checkIn(
        CustomerVisit $visit,
        float $latitude,
        float $longitude,
        ?float $accuracyMeters = null,
        ?string $locationOverrideReason = null,
    ): CustomerVisit {
        $customer = $visit->customer;
        $distance = null;

        if ($customer?->latitude !== null && $customer->longitude !== null) {
            $distance = $this->distanceMeters(
                (float) $customer->latitude,
                (float) $customer->longitude,
                $latitude,
                $longitude,
            );
        }

        $threshold = config('employees.visit_location_warning_meters', 250);
        $warningThreshold = is_numeric($threshold) ? (int) $threshold : 250;
        $warning = $distance !== null && $distance > $warningThreshold;

        $locationOverridden = $warning && filled($locationOverrideReason);

        return $this->transition($visit, VisitStatus::InProgress, [
            'checked_in_at' => now(),
            'check_in_latitude' => $latitude,
            'check_in_longitude' => $longitude,
            'check_in_recorded_at' => now(),
            'check_in_accuracy_meters' => $accuracyMeters,
            'distance_from_customer_meters' => $distance,
            'location_warning' => $warning,
            'location_override_reason' => $locationOverridden ? mb_trim((string) $locationOverrideReason) : null,
            'location_overridden_by' => $locationOverridden ? auth()->id() : null,
            'location_overridden_at' => $locationOverridden ? now() : null,
        ], match (true) {
            $locationOverridden => 'visit.location_warning_overridden',
            $warning => 'visit.location_warning_recorded',
            default => 'visit.checked_in',
        });
    }

    public function complete(
        CustomerVisit $visit,
        VisitOutcome $outcome,
        ?string $outcomeNotes = null,
        bool $followUpRequired = false,
        ?Carbon $followUpDate = null,
        ?string $followUpNote = null,
        ?string $employeeNotes = null,
    ): CustomerVisit {
        if ($outcome === VisitOutcome::FollowUpRequired) {
            $followUpRequired = true;
        }

        if ($followUpRequired && ! $followUpDate instanceof Carbon) {
            throw new DomainException('A follow-up date is required when follow-up is requested.');
        }

        $visit = $this->transition($visit, VisitStatus::Completed, [
            'checked_out_at' => now(),
            'outcome_code' => $outcome,
            'outcome_notes' => $outcomeNotes,
            'outcome' => $outcomeNotes,
            'employee_notes' => $employeeNotes,
            'follow_up_required' => $followUpRequired,
            'follow_up_date' => $followUpDate,
            'follow_up_note' => $followUpNote,
        ], 'visit.completed');

        if ($followUpRequired) {
            $this->followUps->createForVisit($visit->refresh());
        }

        return $visit->refresh();
    }

    public function unableToComplete(CustomerVisit $visit, ?string $reason = null): CustomerVisit
    {
        return $this->transition($visit, VisitStatus::UnableToComplete, [
            'checked_out_at' => $visit->checked_in_at !== null ? now() : $visit->checked_out_at,
            'outcome_code' => VisitOutcome::UnableToComplete,
            'outcome_notes' => $reason,
            'outcome' => $reason,
        ], 'visit.unable_to_complete');
    }

    public function cancel(CustomerVisit $visit, string $reason): CustomerVisit
    {
        if (mb_trim($reason) === '') {
            throw new DomainException('A cancellation reason is required.');
        }

        return $this->transition($visit, VisitStatus::Cancelled, [
            'outcome_notes' => $reason,
            'outcome' => $reason,
        ], 'visit.cancelled');
    }

    /** @param array<string, mixed> $attributes */
    private function transition(CustomerVisit $visit, VisitStatus $target, array $attributes, string $auditEvent): CustomerVisit
    {
        return DB::transaction(function () use ($visit, $target, $attributes, $auditEvent): CustomerVisit {
            $from = $visit->status;

            if (! $from->canTransitionTo($target)) {
                throw InvalidStatusTransition::fromTo($from->value, $target->value);
            }

            if ($target === VisitStatus::Completed && ($attributes['outcome_code'] ?? null) === null) {
                throw new DomainException('A completed visit requires a valid visit outcome.');
            }

            $visit->fill($attributes);
            $visit->status = $target;

            if (
                $visit->checked_in_at !== null
                && $visit->checked_out_at !== null
                && $visit->checked_out_at->lessThan($visit->checked_in_at)
            ) {
                throw new DomainException('Check-out cannot occur before check-in.');
            }

            $visit->save();

            activity()->performedOn($visit)
                ->withChanges([
                    'old' => ['status' => $from->value],
                    'attributes' => ['status' => $target->value],
                ])
                ->withProperties(['source_channel' => 'dashboard'])
                ->log($auditEvent);

            return $visit;
        });
    }

    private function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): int
    {
        $earthRadius = 6371000.0;
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLon = deg2rad($lon2 - $lon1);
        $a = sin($deltaLat / 2) ** 2
            + cos($lat1Rad) * cos($lat2Rad) * sin($deltaLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return (int) round($earthRadius * $c);
    }
}
