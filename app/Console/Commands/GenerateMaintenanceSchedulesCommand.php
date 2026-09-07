<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Support\MaintenanceScheduleGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('maintenance:schedules:generate')]
#[Description('Raise due preventive maintenance occurrences and mark missed ones (WP-3.6, MT-07).')]
final class GenerateMaintenanceSchedulesCommand extends Command
{
    public function handle(MaintenanceScheduleGenerator $generator): int
    {
        try {
            $raised = $generator->raiseDue();
            $missed = $generator->markMissed();
        } catch (Throwable $throwable) {
            $this->components->error('Preventive maintenance schedule sweep failed: '.$throwable->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Preventive maintenance sweep completed: %d occurrence(s) raised, %d marked missed.',
            $raised,
            $missed,
        ));

        return self::SUCCESS;
    }
}
