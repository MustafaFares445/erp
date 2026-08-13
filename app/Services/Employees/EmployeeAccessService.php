<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Models\EmployeeProfile;
use Illuminate\Support\Facades\DB;

final readonly class EmployeeAccessService
{
    public function enable(EmployeeProfile $employee): EmployeeProfile
    {
        return $this->setActive($employee, true, 'employee.access_enabled');
    }

    public function disable(EmployeeProfile $employee): EmployeeProfile
    {
        return $this->setActive($employee, false, 'employee.access_disabled');
    }

    public function archive(EmployeeProfile $employee): void
    {
        DB::transaction(function () use ($employee): void {
            $oldValues = $employee->getAttributes();

            $employee->delete();

            activity()
                ->performedOn($employee)
                ->withChanges([
                    'old' => $oldValues,
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('employee.archived');
        });
    }

    public function restore(EmployeeProfile $employee): EmployeeProfile
    {
        return DB::transaction(function () use ($employee): EmployeeProfile {
            $employee->restore();

            activity()
                ->performedOn($employee)
                ->withChanges([
                    'attributes' => $employee->getAttributes(),
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('employee.restored');

            return $employee;
        });
    }

    private function setActive(EmployeeProfile $employee, bool $isActive, string $action): EmployeeProfile
    {
        return DB::transaction(function () use ($employee, $isActive, $action): EmployeeProfile {
            $oldValues = $employee->getAttributes();

            $employee->update(['is_active' => $isActive]);

            activity()
                ->performedOn($employee)
                ->withChanges([
                    'old' => $oldValues,
                    'attributes' => $employee->getAttributes(),
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log($action);

            return $employee;
        });
    }
}
