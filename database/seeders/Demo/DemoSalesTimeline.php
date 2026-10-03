<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Closure;

/**
 * Collects scene steps from every scenario and replays them in strict chronological order, so
 * document numbers, stock movements, journals and audit rows are created in the same order a
 * real month would have produced them (scenarios are written per story, not per day).
 */
final class DemoSalesTimeline
{
    /** @var list<array{when: string, sequence: int, label: string, step: Closure}> */
    private array $steps = [];

    public function add(string $when, string $label, Closure $step): void
    {
        $this->steps[] = ['when' => $when, 'sequence' => count($this->steps), 'label' => $label, 'step' => $step];
    }

    public function count(): int
    {
        return count($this->steps);
    }

    public function run(DemoContext $context, ?Closure $onStep = null): void
    {
        usort($this->steps, static fn (array $a, array $b): int => [$a['when'], $a['sequence']] <=> [$b['when'], $b['sequence']]);

        foreach ($this->steps as $entry) {
            $context->at($entry['when']);

            if ($onStep instanceof Closure) {
                $onStep($entry['when'], $entry['label']);
            }

            ($entry['step'])();
        }
    }
}
