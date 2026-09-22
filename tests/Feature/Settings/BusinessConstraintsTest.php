<?php

declare(strict_types=1);

use App\Data\Settings\BusinessConstraintData;
use App\Enums\BusinessConstraintEnforcement;
use App\Enums\BusinessConstraintKey;
use App\Models\BusinessConstraint;
use App\Models\PurchaseSetting;
use App\Services\Settings\BusinessConstraints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('answers from the catalogue when the owner has set nothing', function (): void {
    $constraints = app(BusinessConstraints::class);

    expect(BusinessConstraint::query()->count())->toBe(0)
        ->and($constraints->maxDiscountPercent())->toBe(25.0)
        ->and($constraints->maxMarkupPercent())->toBe(100.0)
        ->and($constraints->minGrossMarginPercent())->toBeNull()
        ->and($constraints->receivableAgeingBoundaries())->toBe([30, 60, 90])
        ->and($constraints->payableAgeingBoundaries())->toBe([30, 60, 90])
        ->and($constraints->overdueReminderDays())->toBe([7, 30, 60])
        ->and($constraints->get(BusinessConstraintKey::MaxDiscountPercent)->isDefault)->toBeTrue();
});

it('prefers a stored override over the default', function (): void {
    BusinessConstraint::factory()
        ->limit(BusinessConstraintKey::MaxDiscountPercent, 12.5, BusinessConstraintEnforcement::Block)
        ->create();

    $constraint = app(BusinessConstraints::class)->get(BusinessConstraintKey::MaxDiscountPercent);

    expect($constraint->value)->toBe(12.5)
        ->and($constraint->isDefault)->toBeFalse()
        ->and($constraint->requireEnforcement())->toBe(BusinessConstraintEnforcement::Block);
});

it('prefers a stored ladder over the default', function (): void {
    BusinessConstraint::factory()
        ->policy(BusinessConstraintKey::OverdueReminderDays, [14, 45])
        ->create();

    expect(app(BusinessConstraints::class)->overdueReminderDays())->toBe([14, 45]);
});

it('reads the table once per request no matter how many constraints are asked for', function (): void {
    $constraints = app(BusinessConstraints::class);

    DB::enableQueryLog();

    $constraints->maxDiscountPercent();
    $constraints->maxMarkupPercent();
    $constraints->overdueReminderDays();
    $constraints->all();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(1);
});

it('drops the memo when a constraint is written, so the same request sees its own change', function (): void {
    $constraints = app(BusinessConstraints::class);

    expect($constraints->maxDiscountPercent())->toBe(25.0);

    BusinessConstraint::factory()
        ->limit(BusinessConstraintKey::MaxDiscountPercent, 10.0, BusinessConstraintEnforcement::Warn)
        ->create();

    expect($constraints->maxDiscountPercent())->toBe(10.0);
});

it('drops the memo when a constraint is reset back to its default', function (): void {
    $row = BusinessConstraint::factory()
        ->limit(BusinessConstraintKey::MaxDiscountPercent, 10.0, BusinessConstraintEnforcement::Warn)
        ->create();

    $constraints = app(BusinessConstraints::class);

    expect($constraints->maxDiscountPercent())->toBe(10.0);

    $row->delete();

    expect($constraints->maxDiscountPercent())->toBe(25.0);
});

it('returns every constraint in catalogue order', function (): void {
    $all = app(BusinessConstraints::class)->all();

    expect($all)->toHaveCount(count(BusinessConstraintKey::cases()))
        ->and($all[0]->key)->toBe(BusinessConstraintKey::MaxDiscountPercent);
});

it('filters constraints by the settings section they belong to', function (): void {
    $pricing = app(BusinessConstraints::class)->inGroup('pricing');

    expect($pricing)->toHaveCount(3)
        ->and(array_map(static fn (BusinessConstraintData $data): string => $data->key->group(), $pricing))
        ->toBe(['pricing', 'pricing', 'pricing']);
});

it('reports the enforcement mode of a limit', function (): void {
    expect(app(BusinessConstraints::class)->enforcementFor(BusinessConstraintKey::MaxMarkupPercent))
        ->toBe(BusinessConstraintEnforcement::Block);
});

it('refuses to report an enforcement mode for a policy value', function (): void {
    app(BusinessConstraints::class)->enforcementFor(BusinessConstraintKey::OverdueReminderDays);
})->throws(DomainException::class, 'has no enforcement mode');

it('reads the purchasing threshold through to its own settings row', function (): void {
    PurchaseSetting::query()->create([
        'approval_threshold_amount' => 5000,
        'approval_threshold_currency' => 'aed',
    ]);

    $threshold = app(BusinessConstraints::class)->purchaseApprovalThreshold();

    expect($threshold->amount)->toBe(5000.0)
        ->and($threshold->currency)->toBe('AED')
        ->and($threshold->isDisabled())->toBeFalse();
});

it('reports a zero purchasing threshold as disabled, because everything then needs an approver', function (): void {
    $threshold = app(BusinessConstraints::class)->purchaseApprovalThreshold();

    expect($threshold->amount)->toBe(0.0)
        ->and($threshold->isDisabled())->toBeTrue();
});

it('refuses to read a list constraint as a single value', function (): void {
    app(BusinessConstraints::class)->get(BusinessConstraintKey::OverdueReminderDays)->requireValue();
})->throws(DomainException::class, 'has no value set');

it('refuses to read a scalar constraint as a list', function (): void {
    app(BusinessConstraints::class)->get(BusinessConstraintKey::MaxDiscountPercent)->requireList();
})->throws(DomainException::class, 'is not a list');

it('reports an unset nullable limit as inactive', function (): void {
    $constraints = app(BusinessConstraints::class);

    expect($constraints->get(BusinessConstraintKey::MinGrossMarginPercent)->isActive())->toBeFalse()
        ->and($constraints->get(BusinessConstraintKey::MaxDiscountPercent)->isActive())->toBeTrue()
        ->and($constraints->get(BusinessConstraintKey::OverdueReminderDays)->isActive())->toBeTrue();
});
