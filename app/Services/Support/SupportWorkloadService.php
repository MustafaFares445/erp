<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\TicketStatus;
use App\Models\EmployeeProfile;
use App\Models\SupportTeamMember;
use App\Models\Ticket;

final class SupportWorkloadService
{
    public function openTicketCount(EmployeeProfile $employee): int
    {
        return Ticket::query()
            ->where('assigned_employee_id', $employee->getKey())
            ->whereNotIn('status', [
                TicketStatus::Resolved->value,
                TicketStatus::Closed->value,
                TicketStatus::Cancelled->value,
            ])
            ->count();
    }

    public function hasCapacity(SupportTeamMember $member): bool
    {
        $capacity = $member->capacity ?? $member->team?->default_capacity;

        if ($capacity === null || $capacity <= 0) {
            return true;
        }

        $employee = $member->employee;

        return $employee !== null && $this->openTicketCount($employee) < $capacity;
    }
}
