<?php

declare(strict_types=1);

use App\Enums\BusinessConstraintEnforcement;
use App\Enums\BusinessConstraintKey;
use App\Enums\ConstraintOutcome;
use App\Models\BusinessConstraint;
use App\Models\ConstraintOverride;
use App\Services\Settings\ConstraintGuard;
use App\Services\Settings\Exceptions\ConstraintBreached;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function constraintGuard(): ConstraintGuard
{
    return app(ConstraintGuard::class);
}

function setConstraintDiscountCeiling(float $value, BusinessConstraintEnforcement $enforcement): void
{
    BusinessConstraint::factory()
        ->limit(BusinessConstraintKey::MaxDiscountPercent, $value, $enforcement)
        ->create();
}

it('allows a value at or under a ceiling', function (float $attempted): void {
    setConstraintDiscountCeiling(25.0, BusinessConstraintEnforcement::Block);

    expect(constraintGuard()->assertWithin(BusinessConstraintKey::MaxDiscountPercent, $attempted))
        ->toBe(ConstraintOutcome::Allowed);
})->with([0.0, 10.0, 24.99, 25.0]);

it('allows a value exactly on the ceiling after rounding', function (): void {
    setConstraintDiscountCeiling(25.0, BusinessConstraintEnforcement::Block);

    // 25.00005 is what a percentage recomputed from an amount can look like.
    // Refusing it would make the ceiling unreachable in practice.
    expect(constraintGuard()->assertWithin(BusinessConstraintKey::MaxDiscountPercent, 25.00005))
        ->toBe(ConstraintOutcome::Allowed);
});

it('refuses a value over a ceiling that blocks', function (): void {
    setConstraintDiscountCeiling(25.0, BusinessConstraintEnforcement::Block);

    constraintGuard()->assertWithin(BusinessConstraintKey::MaxDiscountPercent, 40.0);
})->throws(ConstraintBreached::class);

it('names the constraint, the attempt and the limit when it blocks', function (): void {
    setConstraintDiscountCeiling(25.0, BusinessConstraintEnforcement::Block);

    try {
        constraintGuard()->assertWithin(BusinessConstraintKey::MaxDiscountPercent, 40.0);
    } catch (ConstraintBreached $constraintBreached) {
        expect($constraintBreached->getMessage())->toContain('Maximum discount')
            ->and($constraintBreached->getMessage())->toContain('40%')
            ->and($constraintBreached->getMessage())->toContain('25%')
            ->and($constraintBreached->key)->toBe(BusinessConstraintKey::MaxDiscountPercent)
            ->and($constraintBreached->attempted)->toBe(40.0)
            ->and($constraintBreached->limit)->toBe(25.0)
            ->and($constraintBreached->approvable)->toBeFalse();

        return;
    }

    $this->fail('The guard let a blocked value through.');
});

it('warns rather than refuses when the mode is warn only', function (): void {
    setConstraintDiscountCeiling(25.0, BusinessConstraintEnforcement::Warn);

    $outcome = constraintGuard()->assertWithin(BusinessConstraintKey::MaxDiscountPercent, 40.0);

    expect($outcome)->toBe(ConstraintOutcome::Warned)
        ->and($outcome->isBreach())->toBeTrue()
        ->and(ConstraintOutcome::Allowed->isBreach())->toBeFalse();
});

it('demands an approval when the mode requires one and none was supplied', function (): void {
    setConstraintDiscountCeiling(25.0, BusinessConstraintEnforcement::RequireApproval);

    try {
        constraintGuard()->assertWithin(BusinessConstraintKey::MaxDiscountPercent, 40.0);
    } catch (ConstraintBreached $constraintBreached) {
        expect($constraintBreached->approvable)->toBeTrue()
            ->and($constraintBreached->getMessage())->toContain('System Admin approval');

        return;
    }

    $this->fail('The guard let an unapproved breach through.');
});

it('lets an approved value through and says it was overridden', function (): void {
    setConstraintDiscountCeiling(25.0, BusinessConstraintEnforcement::RequireApproval);

    $override = ConstraintOverride::factory()
        ->forValue(BusinessConstraintKey::MaxDiscountPercent, 40.0, 25.0)
        ->create();

    expect(constraintGuard()->assertWithin(BusinessConstraintKey::MaxDiscountPercent, 40.0, $override))
        ->toBe(ConstraintOutcome::Overridden);
});

it('refuses to replay an approval against a different value', function (): void {
    setConstraintDiscountCeiling(25.0, BusinessConstraintEnforcement::RequireApproval);

    $override = ConstraintOverride::factory()
        ->forValue(BusinessConstraintKey::MaxDiscountPercent, 30.0, 25.0)
        ->create();

    // The failure that matters: an approved 30% must not unlock 45%.
    constraintGuard()->assertWithin(BusinessConstraintKey::MaxDiscountPercent, 45.0, $override);
})->throws(ConstraintBreached::class, 'does not authorise this value');

it('refuses to replay an approval granted for a different constraint', function (): void {
    setConstraintDiscountCeiling(25.0, BusinessConstraintEnforcement::RequireApproval);

    $override = ConstraintOverride::factory()
        ->forValue(BusinessConstraintKey::MaxMarkupPercent, 40.0, 100.0)
        ->create();

    constraintGuard()->assertWithin(BusinessConstraintKey::MaxDiscountPercent, 40.0, $override);
})->throws(ConstraintBreached::class, 'does not authorise this value');

it('enforces a floor in the opposite direction from a ceiling', function (): void {
    BusinessConstraint::factory()
        ->limit(BusinessConstraintKey::MinGrossMarginPercent, 15.0, BusinessConstraintEnforcement::Block)
        ->create();

    expect(constraintGuard()->assertWithin(BusinessConstraintKey::MinGrossMarginPercent, 20.0))
        ->toBe(ConstraintOutcome::Allowed)
        ->and(constraintGuard()->assertWithin(BusinessConstraintKey::MinGrossMarginPercent, 15.0))
        ->toBe(ConstraintOutcome::Allowed);

    expect(fn (): ConstraintOutcome => constraintGuard()->assertWithin(BusinessConstraintKey::MinGrossMarginPercent, 5.0))
        ->toThrow(ConstraintBreached::class);
});

it('polices nothing while a nullable limit is unset', function (): void {
    // The margin floor ships unset so no variant already priced below cost
    // starts refusing to save the day this registry lands.
    expect(constraintGuard()->assertWithin(BusinessConstraintKey::MinGrossMarginPercent, -500.0))
        ->toBe(ConstraintOutcome::Allowed);
});

it('refuses to enforce a policy value, which bounds nothing', function (): void {
    constraintGuard()->assertWithin(BusinessConstraintKey::OverdueReminderDays, 99.0);
})->throws(LogicException::class, 'is a policy value, not a limit');

it('tells a form whether a breach could be approved', function (): void {
    setConstraintDiscountCeiling(25.0, BusinessConstraintEnforcement::RequireApproval);

    expect(constraintGuard()->isApprovable(BusinessConstraintKey::MaxDiscountPercent))->toBeTrue()
        ->and(constraintGuard()->isApprovable(BusinessConstraintKey::MaxMarkupPercent))->toBeFalse();
});

it('hands a form the effective limit so it can bound its own field', function (): void {
    setConstraintDiscountCeiling(15.0, BusinessConstraintEnforcement::Block);

    expect(constraintGuard()->limitFor(BusinessConstraintKey::MaxDiscountPercent))->toBe(15.0)
        ->and(constraintGuard()->limitFor(BusinessConstraintKey::MinGrossMarginPercent))->toBeNull()
        ->and(constraintGuard()->constraint(BusinessConstraintKey::MaxDiscountPercent)->key)
        ->toBe(BusinessConstraintKey::MaxDiscountPercent);
});
