<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\BusinessConstraint;
use App\Services\Settings\BusinessConstraints;

/**
 * Keeps the constraint registry honest about two things every writer owes,
 * whether it came through the settings form, a seeder, or a test factory: the
 * request-scoped memo must not outlive the value it cached, and moving a
 * business limit must leave a trail.
 */
final readonly class BusinessConstraintObserver
{
    public function saved(BusinessConstraint $constraint): void
    {
        $this->forgetMemo();

        activity()
            ->performedOn($constraint)
            ->withChanges([
                'old' => $constraint->getOriginal(),
                'attributes' => $constraint->getAttributes(),
            ])
            ->withProperties(['constraint' => $constraint->key->value])
            ->log($constraint->wasRecentlyCreated ? 'settings.constraint.set' : 'settings.constraint.changed');
    }

    public function deleted(BusinessConstraint $constraint): void
    {
        $this->forgetMemo();

        activity()
            ->performedOn($constraint)
            ->withChanges(['old' => $constraint->getOriginal()])
            ->withProperties(['constraint' => $constraint->key->value])
            ->log('settings.constraint.reset');
    }

    /**
     * Resolved from the container rather than injected because the registry is
     * a request-scoped singleton and the observer is constructed by Eloquent.
     */
    private function forgetMemo(): void
    {
        app(BusinessConstraints::class)->forget();
    }
}
