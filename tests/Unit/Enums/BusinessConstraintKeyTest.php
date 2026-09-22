<?php

declare(strict_types=1);

use App\Enums\BusinessConstraintEnforcement;
use App\Enums\BusinessConstraintKey;
use App\Enums\BusinessConstraintKind;
use App\Enums\BusinessConstraintUnit;
use App\Filament\AdminModuleRegistry;

/**
 * The catalogue is the contract every consumer reads, so these run over
 * `cases()` rather than over a hand-written list: a constraint added later
 * cannot slip in with an incoherent definition just because nobody remembered
 * to extend a test.
 */
it('declares a coherent definition for every constraint', function (BusinessConstraintKey $key): void {
    $isLimit = $key->kind() === BusinessConstraintKind::Limit;

    expect($key->isList())->toBe(! $isLimit)
        ->and($key->label())->not->toStartWith('admin.')
        ->and($key->description())->not->toStartWith('admin.')
        ->and($key->affectedModules())->not->toBeEmpty();

    if ($isLimit) {
        // Whatever subset of modes a limit offers, the one it ships with has to
        // be in it, or a fresh install starts out in a state its own settings
        // page would refuse to save.
        expect($key->defaultList())->toBeNull()
            ->and($key->defaultEnforcement())->toBeInstanceOf(BusinessConstraintEnforcement::class)
            ->and($key->allowedEnforcements())->not->toBeEmpty()
            ->and($key->allowedEnforcements())->toContain($key->defaultEnforcement());

        // A limit that is not nullable has to ship with a number, or the first
        // caller after a fresh install gets nothing to compare against.
        if (! $key->isNullable()) {
            expect($key->defaultValue())->toBeFloat();
        }

        return;
    }

    expect($key->defaultValue())->toBeNull()
        ->and($key->defaultEnforcement())->toBeNull()
        ->and($key->allowedEnforcements())->toBe([])
        ->and($key->isNullable())->toBeFalse()
        ->and($key->defaultList())->toBeArray()->not->toBeEmpty();
})->with(BusinessConstraintKey::cases());

it('defaults every policy ladder to an ascending, positive list', function (BusinessConstraintKey $key): void {
    $list = $key->defaultList();

    if ($list === null) {
        expect($key->kind())->toBe(BusinessConstraintKind::Limit);

        return;
    }

    $sorted = $list;
    sort($sorted);

    expect($list)->toBe($sorted)
        ->and(array_unique($list))->toHaveCount(count($list))
        ->and(min($list))->toBeGreaterThan(0);
})->with(BusinessConstraintKey::cases());

it('names each affected module as a real admin module group', function (BusinessConstraintKey $key): void {
    $groups = array_column(AdminModuleRegistry::groups(), 'key');

    foreach ($key->affectedModules() as $module) {
        expect($groups)->toContain($module);
    }
})->with(BusinessConstraintKey::cases());

it('withholds the approval mode from the markup ceiling, which has no deal to approve', function (): void {
    expect(BusinessConstraintKey::MaxMarkupPercent->allowedEnforcements())
        ->toBe([BusinessConstraintEnforcement::Block, BusinessConstraintEnforcement::Warn])
        ->and(BusinessConstraintKey::MaxDiscountPercent->allowedEnforcements())
        ->toBe(BusinessConstraintEnforcement::cases());
});

it('treats the two maximum constraints as ceilings and the minimum as a floor', function (): void {
    expect(BusinessConstraintKey::MaxDiscountPercent->isCeiling())->toBeTrue()
        ->and(BusinessConstraintKey::MaxMarkupPercent->isCeiling())->toBeTrue()
        ->and(BusinessConstraintKey::MinGrossMarginPercent->isCeiling())->toBeFalse();
});

it('reproduces the values that were hardcoded before the registry existed', function (): void {
    // Guards the promise that Phase 0 changed no behaviour: each default is the
    // literal it replaces. The discount ceiling is the one deliberate addition.
    expect(BusinessConstraintKey::MaxMarkupPercent->defaultValue())->toBe(100.0)
        ->and(BusinessConstraintKey::MinGrossMarginPercent->defaultValue())->toBeNull()
        ->and(BusinessConstraintKey::ReceivableAgeingBoundaries->defaultList())->toBe([30, 60, 90])
        ->and(BusinessConstraintKey::PayableAgeingBoundaries->defaultList())->toBe([30, 60, 90])
        ->and(BusinessConstraintKey::OverdueReminderDays->defaultList())->toBe([7, 30, 60])
        ->and(BusinessConstraintKey::MaxDiscountPercent->defaultValue())->toBe(25.0);
});

it('groups every constraint under a section the settings page can render', function (): void {
    expect(BusinessConstraintKey::groups())->toBe(['pricing', 'receivables', 'reminders'])
        ->and(BusinessConstraintKey::inGroup('pricing'))->toHaveCount(3)
        ->and(BusinessConstraintKey::inGroup('receivables'))->toHaveCount(2)
        ->and(BusinessConstraintKey::inGroup('reminders'))->toHaveCount(1)
        ->and(BusinessConstraintKey::inGroup('nothing'))->toBe([]);
});

it('exposes every key value for permission-style iteration', function (): void {
    expect(BusinessConstraintKey::values())
        ->toHaveCount(count(BusinessConstraintKey::cases()))
        ->toContain('pricing.max_discount_percent');
});

it('renders a unit suffix for each measurable unit', function (): void {
    expect(BusinessConstraintUnit::Percent->suffix())->toBe('%')
        ->and(BusinessConstraintUnit::Days->suffix())->toBe(' days')
        ->and(BusinessConstraintUnit::Currency->suffix())->toBeNull()
        ->and(BusinessConstraintUnit::Percent->label())->toBe('Percent')
        ->and(BusinessConstraintUnit::Days->label())->toBe('Days')
        ->and(BusinessConstraintUnit::Currency->label())->toBe('Amount');
});

it('says which kind carries an enforcement mode', function (): void {
    expect(BusinessConstraintKind::Limit->requiresEnforcement())->toBeTrue()
        ->and(BusinessConstraintKind::Policy->requiresEnforcement())->toBeFalse()
        ->and(BusinessConstraintKind::Limit->label())->toBe('Limit')
        ->and(BusinessConstraintKind::Policy->label())->toBe('Policy value');
});

it('accepts an override only under the approval mode', function (): void {
    expect(BusinessConstraintEnforcement::RequireApproval->acceptsOverride())->toBeTrue()
        ->and(BusinessConstraintEnforcement::Block->acceptsOverride())->toBeFalse()
        ->and(BusinessConstraintEnforcement::Warn->acceptsOverride())->toBeFalse()
        ->and(BusinessConstraintEnforcement::values())->toBe(['block', 'require_approval', 'warn']);
});

it('gives each enforcement mode a label, description and colour', function (BusinessConstraintEnforcement $mode): void {
    expect($mode->label())->not->toStartWith('admin.')
        ->and($mode->description())->not->toStartWith('admin.')
        ->and($mode->color())->toBeIn(['danger', 'warning', 'info']);
})->with(BusinessConstraintEnforcement::cases());
