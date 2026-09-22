<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Data\Settings\BusinessConstraintData;
use App\Enums\BusinessConstraintEnforcement;
use App\Enums\BusinessConstraintKey;
use App\Enums\BusinessConstraintKind;
use App\Enums\ConstraintOutcome;
use App\Models\ConstraintOverride;
use App\Services\Settings\Exceptions\ConstraintBreached;
use LogicException;

/**
 * The one place a business limit is enforced.
 *
 * Callers do not branch on the enforcement mode themselves; they hand over a
 * value and either get an outcome back or take a
 * {@see ConstraintBreached}. That keeps the three modes meaning the same thing
 * everywhere, and it keeps enforcement in the service layer where the planned
 * customer and employee applications inherit it — a rule that lives in a
 * Filament form is a rule an API will not have.
 *
 * A {@see ConstraintOutcome::Warned} result is deliberately not a notification:
 * the guard has no opinion about how a warning should look. The caller decides
 * whether that is a Filament banner, a report column, or an audit note.
 */
final readonly class ConstraintGuard
{
    public function __construct(private BusinessConstraints $constraints) {}

    /**
     * @param  ConstraintOverride|null  $override  an approval obtained earlier for this exact value
     *
     * @throws ConstraintBreached
     */
    public function assertWithin(
        BusinessConstraintKey $key,
        float $attempted,
        ?ConstraintOverride $override = null,
    ): ConstraintOutcome {
        if ($key->kind() !== BusinessConstraintKind::Limit) {
            throw new LogicException("{$key->value} is a policy value, not a limit, and enforces nothing.");
        }

        $constraint = $this->constraints->get($key);

        // A nullable limit nobody has opted into polices nothing.
        if (! $constraint->isActive()) {
            return ConstraintOutcome::Allowed;
        }

        $limit = $constraint->requireValue();

        if (self::satisfies($key, $attempted, $limit)) {
            return ConstraintOutcome::Allowed;
        }

        return match ($constraint->requireEnforcement()) {
            BusinessConstraintEnforcement::Warn => ConstraintOutcome::Warned,
            BusinessConstraintEnforcement::Block => throw ConstraintBreached::blocked($key, $attempted, $limit),
            BusinessConstraintEnforcement::RequireApproval => self::resolveOverride($key, $attempted, $limit, $override),
        };
    }

    /**
     * Whether a breach of this constraint could be approved rather than
     * refused, so a form can offer the affordance before the user tries.
     */
    public function isApprovable(BusinessConstraintKey $key): bool
    {
        return $this->constraints->get($key)->requireEnforcement()->acceptsOverride();
    }

    /**
     * The effective limit, for a form deriving its own bounds.
     *
     * Null means the constraint is unset and the field should impose no bound
     * of its own.
     */
    public function limitFor(BusinessConstraintKey $key): ?float
    {
        return $this->constraints->get($key)->value;
    }

    public function constraint(BusinessConstraintKey $key): BusinessConstraintData
    {
        return $this->constraints->get($key);
    }

    /** @throws ConstraintBreached */
    private static function resolveOverride(
        BusinessConstraintKey $key,
        float $attempted,
        float $limit,
        ?ConstraintOverride $override,
    ): ConstraintOutcome {
        if (! $override instanceof ConstraintOverride) {
            throw ConstraintBreached::approvalRequired($key, $attempted, $limit);
        }

        // An approval is for an amount. Without this, an approved 30% discount
        // could be replayed to push through 45%.
        if (! $override->covers($key, $attempted)) {
            throw ConstraintBreached::overrideDoesNotApply($key, $attempted, $limit);
        }

        return ConstraintOutcome::Overridden;
    }

    private static function satisfies(BusinessConstraintKey $key, float $attempted, float $limit): bool
    {
        return $key->isCeiling()
            ? $attempted <= $limit + BusinessConstraintKey::ValueTolerance
            : $attempted >= $limit - BusinessConstraintKey::ValueTolerance;
    }
}
