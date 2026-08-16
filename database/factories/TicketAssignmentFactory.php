<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EmployeeProfile;
use App\Models\Ticket;
use App\Models\TicketAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketAssignment>
 */
final class TicketAssignmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'employee_id' => EmployeeProfile::factory(),
            'assigned_by' => User::factory(),
            'assigned_at' => now(),
        ];
    }
}
