<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\Database\DisposableDatabaseGuard;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\DatabaseManager;

final readonly class GuardDestructiveDatabaseCommand
{
    public function __construct(
        private DisposableDatabaseGuard $guard,
        private DatabaseManager $databases,
    ) {}

    public function handle(CommandStarting $event): void
    {
        if (! in_array($event->command, DisposableDatabaseGuard::DESTRUCTIVE_COMMANDS, true)) {
            return;
        }

        $connectionName = $event->input->getParameterOption('--database', null);

        $this->guard->assertDisposable(
            $this->databases->connection(is_string($connectionName) && $connectionName !== '' ? $connectionName : null),
        );
    }
}
