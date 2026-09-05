<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Exceptions\Domain\IllegalStatusTransition;
use BackedEnum;
use LogicException;

trait TransitionsDocumentStatus
{
    public function assertCanTransitionTo(BackedEnum $target): void
    {
        $current = $this->getAttribute('status');

        if (! $current instanceof BackedEnum) {
            throw new LogicException(sprintf(
                '%s must cast its status attribute to a backed enum before transition guards are used.',
                class_basename($this),
            ));
        }

        if ($current::class !== $target::class) {
            throw IllegalStatusTransition::between(
                class_basename($this),
                (string) $current->value,
                (string) $target->value,
            );
        }

        $transition = [$current, 'canTransitionTo'];

        if (! is_callable($transition)) {
            throw new LogicException(sprintf(
                '%s must provide canTransitionTo() on its status enum.',
                $current::class,
            ));
        }

        if ($transition($target) !== true) {
            throw IllegalStatusTransition::between(
                class_basename($this),
                (string) $current->value,
                (string) $target->value,
            );
        }
    }
}
