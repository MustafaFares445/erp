<?php

declare(strict_types=1);

namespace App\Filament\Components;

use BackedEnum;
use Closure;
use Filament\Infolists\Components\Entry;

final class WorkflowStepper extends Entry
{
    protected string $view = 'filament.components.workflow-stepper';

    /** @var array<string, string>|Closure */
    protected array|Closure $steps = [];

    /** @var list<string>|Closure */
    protected array|Closure $terminalKeys = [];

    /** @param array<string, string>|Closure $steps */
    public function steps(array|Closure $steps): static
    {
        $this->steps = $steps;

        return $this;
    }

    /** @return array<string, string> */
    public function getSteps(): array
    {
        $steps = $this->evaluate($this->steps);

        if (! is_array($steps)) {
            return [];
        }

        $normalized = [];

        foreach ($steps as $key => $label) {
            if (is_string($key) && is_string($label)) {
                $normalized[$key] = $label;
            }
        }

        return $normalized;
    }

    /** @param list<string>|Closure $keys */
    public function terminalKeys(array|Closure $keys): static
    {
        $this->terminalKeys = $keys;

        return $this;
    }

    /** @return list<string> */
    public function getTerminalKeys(): array
    {
        $keys = $this->evaluate($this->terminalKeys);

        return is_array($keys) ? array_values(array_filter($keys, is_string(...))) : [];
    }

    public function currentKey(): ?string
    {
        $state = $this->getState();

        if ($state instanceof BackedEnum) {
            return (string) $state->value;
        }

        return is_scalar($state) ? (string) $state : null;
    }

    public function statusFor(string $key): string
    {
        $keys = array_keys($this->getSteps());
        $current = $this->currentKey();

        if ($current !== null && in_array($current, $this->getTerminalKeys(), true)) {
            return $key === $current ? 'terminal' : 'upcoming';
        }

        $currentIndex = array_search($current, $keys, true);
        $stepIndex = array_search($key, $keys, true);

        if ($currentIndex === false || $stepIndex === false) {
            return 'upcoming';
        }

        return match (true) {
            $stepIndex < $currentIndex => 'completed',
            $stepIndex === $currentIndex => 'current',
            default => 'upcoming',
        };
    }
}
