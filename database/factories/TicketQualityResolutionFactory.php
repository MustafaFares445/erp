<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\QualityResolutionType;
use App\Models\Ticket;
use App\Models\TicketQualityResolution;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TicketQualityResolution> */
final class TicketQualityResolutionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'resolution_type' => QualityResolutionType::NoDefectFound,
            'resolved_at' => now(),
        ];
    }
}
