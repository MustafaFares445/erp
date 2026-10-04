<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\ServiceAppointmentStatus;
use App\Models\EmployeeProfile;
use App\Models\ServiceAppointment;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

final class TechnicianAvailabilityService
{
    public function hasConflict(
        EmployeeProfile $employee,
        CarbonInterface $start,
        CarbonInterface $end,
        ?ServiceAppointment $ignore = null,
    ): bool {
        return ServiceAppointment::query()
            ->where('employee_id', $employee->getKey())
            ->whereNotIn('status', [
                ServiceAppointmentStatus::Completed->value,
                ServiceAppointmentStatus::Cancelled->value,
            ])
            ->when($ignore, static fn (Builder $query, ServiceAppointment $ignored): Builder => $query->whereKeyNot($ignored->getKey()))
            ->where('scheduled_start_at', '<', $end)
            ->where('scheduled_end_at', '>', $start)
            ->exists();
    }

    /** @return list<int> */
    public function availableEmployeeIds(CarbonInterface $start, CarbonInterface $end): array
    {
        $employees = EmployeeProfile::query()
            ->where('is_active', true)
            ->whereDoesntHave('serviceAppointments', static function (Builder $query) use ($start, $end): void {
                $query->whereNotIn('status', [
                    ServiceAppointmentStatus::Completed->value,
                    ServiceAppointmentStatus::Cancelled->value,
                ])
                    ->where('scheduled_start_at', '<', $end)
                    ->where('scheduled_end_at', '>', $start);
            })
            ->get(['id']);

        return array_values($employees->map(static fn (EmployeeProfile $employee): int => $employee->id)->all());
    }
}
