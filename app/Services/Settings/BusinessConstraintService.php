<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Enums\BusinessConstraintEnforcement;
use App\Enums\BusinessConstraintKey;
use App\Models\BusinessConstraint;
use App\Models\ConstraintOverride;
use App\Models\User;
use App\Observers\BusinessConstraintObserver;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The one place a constraint's value, or an approval to cross it, is written.
 *
 * Takes an explicit actor rather than reading the authenticated user, as every
 * service in this codebase does — the Filament layer is where the current user
 * is resolved and handed over, which is what keeps these services usable from
 * a console command or an API.
 */
final readonly class BusinessConstraintService
{
    public function __construct(private BusinessConstraints $constraints) {}

    /**
     * Set a scalar limit and how it is enforced.
     *
     * Passing a null value is only legal for a constraint that declares itself
     * nullable, and means "stop policing this" — distinct from resetting it,
     * which restores the catalogue default.
     */
    public function setValue(
        BusinessConstraintKey $key,
        ?float $value,
        BusinessConstraintEnforcement $enforcement,
        User $actor,
    ): BusinessConstraint {
        if ($key->isList()) {
            throw new DomainException("The {$key->value} constraint holds a list, not a single value.");
        }

        return $this->persist($key, [
            'value' => $value,
            'value_json' => null,
            'enforcement' => $enforcement,
        ], $actor);
    }

    /**
     * Set an ordered policy ladder.
     *
     * @param  list<int>  $boundaries
     */
    public function setList(BusinessConstraintKey $key, array $boundaries, User $actor): BusinessConstraint
    {
        if (! $key->isList()) {
            throw new DomainException("The {$key->value} constraint holds a single value, not a list.");
        }

        return $this->persist($key, [
            'value' => null,
            'value_json' => $boundaries,
            'enforcement' => null,
        ], $actor);
    }

    /**
     * Drop the owner's override so the catalogue default applies again.
     *
     * Deleting the row is the reset: absence means "default" by design, so
     * there is no second representation of the same state to keep in step.
     * The audit entry and the memo invalidation come from
     * {@see BusinessConstraintObserver}, which covers every
     * writer rather than only this one.
     */
    public function reset(BusinessConstraintKey $key): void
    {
        DB::transaction(static function () use ($key): void {
            $row = BusinessConstraint::query()->where('key', $key->value)->first();

            $row?->delete();
        });
    }

    /**
     * Record a named approval to cross a constraint once, at one value.
     *
     * The limit is snapshotted onto the row rather than re-read later, so
     * raising the ceiling next quarter never makes a past approval look
     * unnecessary.
     */
    public function approveOverride(
        BusinessConstraintKey $key,
        float $attempted,
        string $reason,
        User $actor,
        ?Model $subject = null,
    ): ConstraintOverride {
        $trimmed = mb_trim($reason);

        if ($trimmed === '') {
            throw new DomainException(__('admin.constraints.errors.reason_required'));
        }

        $constraint = $this->constraints->get($key);

        if (! $constraint->requireEnforcement()->acceptsOverride()) {
            throw new DomainException(__('admin.constraints.errors.not_approvable', [
                'constraint' => $key->label(),
            ]));
        }

        $override = ConstraintOverride::query()->create([
            'constraint_key' => $key,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'attempted_value' => $attempted,
            'limit_value' => $constraint->requireValue(),
            'reason' => $trimmed,
            'approved_by' => $actor->getKey(),
            'approved_at' => now(),
        ]);

        activity()
            ->performedOn($override)
            ->causedBy($actor)
            ->withProperties([
                'constraint' => $key->value,
                'attempted' => $attempted,
                'limit' => $constraint->requireValue(),
            ])
            ->log('settings.constraint.overridden');

        return $override;
    }

    /**
     * @param  array{value: float|null, value_json: list<int>|null, enforcement: BusinessConstraintEnforcement|null}  $attributes
     */
    private function persist(BusinessConstraintKey $key, array $attributes, User $actor): BusinessConstraint
    {
        return DB::transaction(static fn (): BusinessConstraint => BusinessConstraint::query()->updateOrCreate(
            ['key' => $key],
            [...$attributes, 'updated_by' => $actor->getKey()],
        ));
    }
}
