<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\VoiceNoteStatus;
use App\Models\CustomerVisit;
use App\Models\EmployeeProfile;
use App\Models\EmployeeVoiceNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeVoiceNote>
 */
final class EmployeeVoiceNoteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_visit_id' => CustomerVisit::factory(),
            'employee_id' => EmployeeProfile::factory(),
            'language' => null,
            'duration_seconds' => fake()->numberBetween(10, 180),
            'status' => VoiceNoteStatus::Pending,
        ];
    }
}
