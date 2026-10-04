<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Base class for every demo-month seeder.
 *
 * Guarantees, for each concrete seeder run on its own or through {@see DemoMonthSeeder}:
 *  - it refuses to run in production;
 *  - Eloquent model events are live (observers and saving hooks create real side effects);
 *  - notifications are delivered synchronously to the array mailer and never leave the process;
 *  - no real transcription provider or payment gateway can be reached;
 *  - the scene clock and signed-in user are always released afterwards.
 */
abstract class DemoSeeder extends Seeder
{
    /** Console command driving this run, when seeded from Artisan (the parent property is untyped and never null in its phpdoc). */
    private ?Command $console = null;

    #[\Override]
    public function setCommand(Command $command): static
    {
        $this->console = $command;

        return parent::setCommand($command);
    }

    final public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo seeding is disabled in production. Refusing to run '.static::class.'.');
        }

        $context = DemoContext::make();
        $previousDispatcher = Model::getEventDispatcher();
        Model::setEventDispatcher(app('events'));

        config([
            'queue.default' => 'sync',
            'mail.default' => 'array',
            'employees.transcription.driver' => 'fake',
        ]);

        try {
            $this->seed($context);
        } finally {
            $context->finish();

            if ($previousDispatcher !== null) {
                Model::setEventDispatcher($previousDispatcher);
            }
        }
    }

    abstract protected function seed(DemoContext $context): void;

    protected function note(string $message): void
    {
        if ($this->console instanceof Command) {
            $this->console->getOutput()->writeln('  <comment>demo</comment> '.$message);
        }
    }
}
