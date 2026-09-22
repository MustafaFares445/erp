<?php

declare(strict_types=1);

use App\Enums\BusinessConstraintEnforcement;
use App\Enums\BusinessConstraintKey;
use App\Models\AuditLog;
use App\Models\BusinessConstraint;
use App\Models\ConstraintOverride;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\Settings\BusinessConstraints;
use App\Services\Settings\BusinessConstraintService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actor = User::factory()->admin()->create();
    $this->service = app(BusinessConstraintService::class);
});

it('stores a limit and the mode that enforces it', function (): void {
    $row = $this->service->setValue(
        BusinessConstraintKey::MaxDiscountPercent,
        15.0,
        BusinessConstraintEnforcement::Block,
        $this->actor,
    );

    expect($row->key)->toBe(BusinessConstraintKey::MaxDiscountPercent)
        ->and((float) $row->value)->toBe(15.0)
        ->and($row->enforcement)->toBe(BusinessConstraintEnforcement::Block)
        ->and($row->updated_by)->toBe($this->actor->id)
        ->and(app(BusinessConstraints::class)->maxDiscountPercent())->toBe(15.0);
});

it('updates the existing row rather than adding a second one for the same key', function (): void {
    $this->service->setValue(BusinessConstraintKey::MaxDiscountPercent, 15.0, BusinessConstraintEnforcement::Block, $this->actor);
    $this->service->setValue(BusinessConstraintKey::MaxDiscountPercent, 20.0, BusinessConstraintEnforcement::Warn, $this->actor);

    expect(BusinessConstraint::query()->where('key', BusinessConstraintKey::MaxDiscountPercent->value)->count())->toBe(1)
        ->and(app(BusinessConstraints::class)->maxDiscountPercent())->toBe(20.0);
});

it('lets a nullable limit be turned off without losing its enforcement mode', function (): void {
    $this->service->setValue(BusinessConstraintKey::MinGrossMarginPercent, 10.0, BusinessConstraintEnforcement::Block, $this->actor);
    $this->service->setValue(BusinessConstraintKey::MinGrossMarginPercent, null, BusinessConstraintEnforcement::Block, $this->actor);

    $constraint = app(BusinessConstraints::class)->get(BusinessConstraintKey::MinGrossMarginPercent);

    // Unset is not the same as reset: the row still exists, so the owner's
    // decision to stop policing is recorded rather than inferred.
    expect($constraint->value)->toBeNull()
        ->and($constraint->isDefault)->toBeFalse()
        ->and($constraint->isActive())->toBeFalse();
});

it('stores a policy ladder', function (): void {
    $this->service->setList(BusinessConstraintKey::ReceivableAgeingBoundaries, [15, 45, 75], $this->actor);

    expect(app(BusinessConstraints::class)->receivableAgeingBoundaries())->toBe([15, 45, 75]);
});

it('refuses to store a list where a single value belongs', function (): void {
    $this->service->setList(BusinessConstraintKey::MaxDiscountPercent, [10, 20], $this->actor);
})->throws(DomainException::class, 'holds a single value, not a list');

it('refuses to store a single value where a list belongs', function (): void {
    $this->service->setValue(
        BusinessConstraintKey::OverdueReminderDays,
        30.0,
        BusinessConstraintEnforcement::Block,
        $this->actor,
    );
})->throws(DomainException::class, 'holds a list, not a single value');

it('restores the catalogue default by dropping the row', function (): void {
    $this->service->setValue(BusinessConstraintKey::MaxDiscountPercent, 15.0, BusinessConstraintEnforcement::Block, $this->actor);

    $this->service->reset(BusinessConstraintKey::MaxDiscountPercent);

    expect(BusinessConstraint::query()->count())->toBe(0)
        ->and(app(BusinessConstraints::class)->maxDiscountPercent())->toBe(25.0)
        ->and(app(BusinessConstraints::class)->get(BusinessConstraintKey::MaxDiscountPercent)->isDefault)->toBeTrue();
});

it('treats resetting an already-default constraint as a no-op', function (): void {
    $this->service->reset(BusinessConstraintKey::MaxDiscountPercent);

    expect(BusinessConstraint::query()->count())->toBe(0);
});

it('leaves an audit entry for every change to a limit', function (): void {
    $this->actingAs($this->actor);

    $this->service->setValue(BusinessConstraintKey::MaxDiscountPercent, 15.0, BusinessConstraintEnforcement::Block, $this->actor);
    $this->service->setValue(BusinessConstraintKey::MaxDiscountPercent, 20.0, BusinessConstraintEnforcement::Block, $this->actor);
    $this->service->reset(BusinessConstraintKey::MaxDiscountPercent);

    $descriptions = AuditLog::query()
        ->whereIn('description', ['settings.constraint.set', 'settings.constraint.changed', 'settings.constraint.reset'])
        ->orderBy('id')
        ->pluck('description')
        ->all();

    expect($descriptions)->toBe([
        'settings.constraint.set',
        'settings.constraint.changed',
        'settings.constraint.reset',
    ]);
});

it('records a named, reasoned approval to cross a constraint', function (): void {
    $override = $this->service->approveOverride(
        BusinessConstraintKey::MaxDiscountPercent,
        40.0,
        '  Agreed with the customer for the annual renewal.  ',
        $this->actor,
    );

    expect($override->constraint_key)->toBe(BusinessConstraintKey::MaxDiscountPercent)
        ->and((float) $override->attempted_value)->toBe(40.0)
        ->and((float) $override->limit_value)->toBe(25.0)
        ->and($override->reason)->toBe('Agreed with the customer for the annual renewal.')
        ->and($override->approved_by)->toBe($this->actor->id)
        ->and($override->approvedBy->is($this->actor))->toBeTrue()
        ->and(AuditLog::query()->where('description', 'settings.constraint.overridden')->exists())->toBeTrue();
});

it('snapshots the limit onto the approval so raising it later never rewrites history', function (): void {
    $override = $this->service->approveOverride(BusinessConstraintKey::MaxDiscountPercent, 40.0, 'Renewal.', $this->actor);

    $this->service->setValue(BusinessConstraintKey::MaxDiscountPercent, 50.0, BusinessConstraintEnforcement::RequireApproval, $this->actor);

    expect((float) $override->fresh()->limit_value)->toBe(25.0);
});

it('attaches an approval to the record it was granted for', function (): void {
    $customer = CustomerProfile::factory()->create();

    $override = $this->service->approveOverride(
        BusinessConstraintKey::MaxDiscountPercent,
        40.0,
        'Renewal.',
        $this->actor,
        $customer,
    );

    expect($override->subject_id)->toBe($customer->id)
        ->and($override->subject->is($customer))->toBeTrue();
});

it('refuses an approval with no reason', function (): void {
    $this->service->approveOverride(BusinessConstraintKey::MaxDiscountPercent, 40.0, '   ', $this->actor);
})->throws(DomainException::class, 'A reason is required');

it('refuses to approve past a constraint that blocks', function (): void {
    $this->service->setValue(BusinessConstraintKey::MaxDiscountPercent, 25.0, BusinessConstraintEnforcement::Block, $this->actor);

    $this->service->approveOverride(BusinessConstraintKey::MaxDiscountPercent, 40.0, 'Renewal.', $this->actor);
})->throws(DomainException::class, 'cannot be approved past');

it('keeps an approval immutable once granted', function (): void {
    $override = ConstraintOverride::factory()->create();

    expect(fn (): bool => $override->update(['reason' => 'rewritten']))
        ->toThrow(LogicException::class, 'Constraint overrides are immutable.')
        ->and(fn (): ?bool => $override->delete())
        ->toThrow(LogicException::class, 'Constraint overrides are immutable.');
});
