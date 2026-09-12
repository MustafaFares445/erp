<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Enums\VisitStatus;
use App\Models\CustomerVisit;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Models\VisitGpsLog;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class EmployeeVisitFieldService
{
    public function checkIn(User $actor, int $visitId, float $latitude, float $longitude): CustomerVisit
    {
        return DB::transaction(function () use ($actor, $visitId, $latitude, $longitude): CustomerVisit {
            $profile = $this->profile($actor);
            $visit = $this->lockOwnedVisit($profile, $visitId);

            if ($visit->status !== VisitStatus::Planned) {
                throw new DomainException('Only a planned visit can be checked in.');
            }

            $now = now();
            $visit->forceFill([
                'status' => VisitStatus::InProgress,
                'checked_in_at' => $now,
                'updated_by' => $actor->getKey(),
            ])->save();
            $this->gps($visit, $latitude, $longitude, $now);

            activity()->performedOn($visit)->causedBy($actor)
                ->withProperties(['source_channel' => 'employee_api', 'latitude' => $latitude, 'longitude' => $longitude])
                ->log('employees.visit.checked_in');

            return $visit->refresh();
        }, attempts: 5);
    }

    public function checkOut(
        User $actor,
        int $visitId,
        float $latitude,
        float $longitude,
        ?string $outcome = null,
    ): CustomerVisit {
        return DB::transaction(function () use ($actor, $visitId, $latitude, $longitude, $outcome): CustomerVisit {
            $profile = $this->profile($actor);
            $visit = $this->lockOwnedVisit($profile, $visitId);

            if ($visit->status !== VisitStatus::InProgress || $visit->checked_in_at === null) {
                throw new DomainException('Only an in-progress checked-in visit can be checked out.');
            }

            $now = now();
            $visit->forceFill([
                'status' => VisitStatus::Completed,
                'checked_out_at' => $now,
                'outcome' => $outcome,
                'updated_by' => $actor->getKey(),
            ])->save();
            $this->gps($visit, $latitude, $longitude, $now);

            activity()->performedOn($visit)->causedBy($actor)
                ->withProperties(['source_channel' => 'employee_api', 'latitude' => $latitude, 'longitude' => $longitude])
                ->log('employees.visit.checked_out');

            return $visit->refresh();
        }, attempts: 5);
    }

    private function profile(User $actor): EmployeeProfile
    {
        $profile = $actor->employeeProfile;

        if (! $actor->isEmployee() || ! $profile instanceof EmployeeProfile || ! $profile->is_active) {
            throw new DomainException('An active employee profile is required.');
        }

        return $profile;
    }

    private function lockOwnedVisit(EmployeeProfile $profile, int $visitId): CustomerVisit
    {
        return CustomerVisit::query()
            ->whereKey($visitId)
            ->where('employee_id', $profile->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function gps(CustomerVisit $visit, float $latitude, float $longitude, mixed $recordedAt): void
    {
        VisitGpsLog::query()->create([
            'customer_visit_id' => $visit->getKey(),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'recorded_at' => $recordedAt,
        ]);
    }
}
