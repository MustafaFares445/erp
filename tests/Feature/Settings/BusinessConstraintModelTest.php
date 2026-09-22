<?php

declare(strict_types=1);

use App\Enums\BusinessConstraintEnforcement;
use App\Enums\BusinessConstraintKey;
use App\Models\BusinessConstraint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The settings form is one writer among several. A seeder, a factory or a
 * future API reaches the same table, so the shape rules live on the model
 * rather than only in the form that happens to be the first writer today.
 */
function storeConstraint(array $attributes): BusinessConstraint
{
    return BusinessConstraint::query()->create($attributes);
}

it('refuses a scalar value on a constraint that holds a ladder', function (): void {
    storeConstraint([
        'key' => BusinessConstraintKey::OverdueReminderDays,
        'value' => 30.0,
        'value_json' => [7, 30],
        'enforcement' => null,
    ]);
})->throws(DomainException::class, 'holds a list, not a single value');

it('refuses a ladder on a constraint that holds one value', function (): void {
    storeConstraint([
        'key' => BusinessConstraintKey::MaxDiscountPercent,
        'value' => null,
        'value_json' => [10, 20],
        'enforcement' => BusinessConstraintEnforcement::Block,
    ]);
})->throws(DomainException::class, 'holds a single value, not a list');

it('refuses a missing value on a limit that is not nullable', function (): void {
    storeConstraint([
        'key' => BusinessConstraintKey::MaxDiscountPercent,
        'value' => null,
        'value_json' => null,
        'enforcement' => BusinessConstraintEnforcement::Block,
    ]);
})->throws(DomainException::class, 'requires a value');

it('accepts a missing value on the one limit that declares itself nullable', function (): void {
    $row = storeConstraint([
        'key' => BusinessConstraintKey::MinGrossMarginPercent,
        'value' => null,
        'value_json' => null,
        'enforcement' => BusinessConstraintEnforcement::Warn,
        'updated_by' => User::factory()->admin()->create()->id,
    ]);

    expect($row->value)->toBeNull();
});

it('refuses an empty ladder', function (): void {
    storeConstraint([
        'key' => BusinessConstraintKey::OverdueReminderDays,
        'value' => null,
        'value_json' => [],
        'enforcement' => null,
    ]);
})->throws(DomainException::class, 'requires at least one boundary');

it('refuses a ladder that does not climb', function (array $boundaries): void {
    // An unsorted or duplicated ladder produces an empty ageing bucket, or a
    // reminder that can never fire, long after anyone would connect the two.
    storeConstraint([
        'key' => BusinessConstraintKey::OverdueReminderDays,
        'value' => null,
        'value_json' => $boundaries,
        'enforcement' => null,
    ]);
})->throws(DomainException::class, 'must ascend')->with([
    'descending' => [[60, 30]],
    'duplicated' => [[30, 30]],
    'zero' => [[0, 30]],
    'negative' => [[-7, 30]],
]);

it('refuses an enforcement mode on a policy value, which polices nothing', function (): void {
    storeConstraint([
        'key' => BusinessConstraintKey::OverdueReminderDays,
        'value' => null,
        'value_json' => [7, 30],
        'enforcement' => BusinessConstraintEnforcement::Block,
    ]);
})->throws(DomainException::class, 'has nothing to enforce');

it('refuses a limit with no enforcement mode', function (): void {
    storeConstraint([
        'key' => BusinessConstraintKey::MaxDiscountPercent,
        'value' => 20.0,
        'value_json' => null,
        'enforcement' => null,
    ]);
})->throws(DomainException::class, 'requires an enforcement mode');

it('refuses an enforcement mode the constraint does not offer', function (): void {
    // The markup ceiling has no deal for an approval to attach to, so it does
    // not offer that mode at all.
    storeConstraint([
        'key' => BusinessConstraintKey::MaxMarkupPercent,
        'value' => 120.0,
        'value_json' => null,
        'enforcement' => BusinessConstraintEnforcement::RequireApproval,
    ]);
})->throws(DomainException::class, 'is not an allowed enforcement mode');

it('names the user who last moved a limit', function (): void {
    $actor = User::factory()->admin()->create();

    $row = BusinessConstraint::factory()->create(['updated_by' => $actor->id]);

    expect($row->updatedBy->is($actor))->toBeTrue();
});
