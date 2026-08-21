<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\TicketPriority;
use App\Models\SlaPolicy;
use Illuminate\Database\Seeder;

/**
 * Seeds the 4 fixed {@see SlaPolicy} rows — one per {@see TicketPriority} —
 * with the defaults settled by clarification (2026-08-13, spec.md FR-051):
 * Urgent 1h/4h, High 4h/24h, Normal 8h/48h, Low 24h/72h. Idempotent via
 * `updateOrCreate` on `priority`, mirroring {@see PackageTypeSeeder}.
 */
final class SlaPolicySeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['priority' => TicketPriority::Urgent, 'response' => 60, 'resolution' => 240],
            ['priority' => TicketPriority::High, 'response' => 240, 'resolution' => 1440],
            ['priority' => TicketPriority::Normal, 'response' => 480, 'resolution' => 2880],
            ['priority' => TicketPriority::Low, 'response' => 1440, 'resolution' => 4320],
        ] as $defaults) {
            SlaPolicy::query()->updateOrCreate(
                ['priority' => $defaults['priority']],
                [
                    'response_target_minutes' => $defaults['response'],
                    'resolution_target_minutes' => $defaults['resolution'],
                ],
            );
        }
    }
}
