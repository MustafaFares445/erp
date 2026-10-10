<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Base for local/demo-only scenario seeders. Keep domain events enabled while
 * isolating fake integrations, scene clocks and runtime settings from the caller.
 */
abstract class DemoSeeder extends Seeder
{
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

        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo seeding is disabled outside local or testing environments. Refusing to run '.static::class.'.');
        }

        $context = DemoContext::make();
        $previousDispatcher = Model::getEventDispatcher();
        $previousUser = Auth::user();
        $configKeys = ['queue.default', 'mail.default', 'employees.transcription.driver'];
        $previousConfig = [];

        foreach ($configKeys as $key) {
            $previousConfig[$key] = config($key);
        }

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
            config($previousConfig);

            if ($previousUser !== null) {
                Auth::setUser($previousUser);
            }

            if ($previousDispatcher !== null) {
                Model::setEventDispatcher($previousDispatcher);
            } else {
                Model::unsetEventDispatcher();
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
