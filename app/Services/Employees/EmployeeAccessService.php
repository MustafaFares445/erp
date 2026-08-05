<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Models\EmployeeProfile;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

final readonly class EmployeeAccessService
{
    public function __construct(private AuditLogger $auditLogger) {}

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

            $this->auditLogger->log(
                action: 'employee.archived',
                entity: $employee,
                oldValues: $oldValues,
            );
        });
    }

    public function restore(EmployeeProfile $employee): EmployeeProfile
    {
        return DB::transaction(function () use ($employee): EmployeeProfile {
            $employee->restore();

            $this->auditLogger->log(
                action: 'employee.restored',
                entity: $employee,
                newValues: $employee->getAttributes(),
            );

            return $employee;
        });
    }

    private function setActive(EmployeeProfile $employee, bool $isActive, string $action): EmployeeProfile
    {
        return DB::transaction(function () use ($employee, $isActive, $action): EmployeeProfile {
            $oldValues = $employee->getAttributes();

            $employee->update(['is_active' => $isActive]);

            $this->auditLogger->log(
                action: $action,
                entity: $employee,
                oldValues: $oldValues,
                newValues: $employee->getAttributes(),
            );

            return $employee;
        });
    }
}
