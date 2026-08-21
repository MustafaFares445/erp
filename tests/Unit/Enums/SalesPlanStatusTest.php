<?php

declare(strict_types=1);

use App\Enums\SalesPlanStatus;

it('allows exactly the documented transitions', function (): void {
    expect(SalesPlanStatus::Draft->canTransitionTo(SalesPlanStatus::Active))->toBeTrue()
        ->and(SalesPlanStatus::Draft->canTransitionTo(SalesPlanStatus::Archived))->toBeTrue()
        ->and(SalesPlanStatus::Active->canTransitionTo(SalesPlanStatus::Paused))->toBeTrue()
        ->and(SalesPlanStatus::Active->canTransitionTo(SalesPlanStatus::Completed))->toBeTrue()
        ->and(SalesPlanStatus::Paused->canTransitionTo(SalesPlanStatus::Active))->toBeTrue()
        ->and(SalesPlanStatus::Paused->canTransitionTo(SalesPlanStatus::Archived))->toBeTrue()
        ->and(SalesPlanStatus::Completed->canTransitionTo(SalesPlanStatus::Archived))->toBeTrue();
});

it('rejects every undocumented transition', function (): void {
    expect(SalesPlanStatus::Draft->canTransitionTo(SalesPlanStatus::Paused))->toBeFalse()
        ->and(SalesPlanStatus::Draft->canTransitionTo(SalesPlanStatus::Completed))->toBeFalse()
        ->and(SalesPlanStatus::Active->canTransitionTo(SalesPlanStatus::Draft))->toBeFalse()
        ->and(SalesPlanStatus::Completed->canTransitionTo(SalesPlanStatus::Active))->toBeFalse()
        ->and(SalesPlanStatus::Completed->canTransitionTo(SalesPlanStatus::Paused))->toBeFalse()
        ->and(SalesPlanStatus::Archived->canTransitionTo(SalesPlanStatus::Draft))->toBeFalse()
        ->and(SalesPlanStatus::Archived->canTransitionTo(SalesPlanStatus::Active))->toBeFalse()
        ->and(SalesPlanStatus::Archived->canTransitionTo(SalesPlanStatus::Paused))->toBeFalse()
        ->and(SalesPlanStatus::Archived->canTransitionTo(SalesPlanStatus::Completed))->toBeFalse();
});

it('rejects every self-transition', function (): void {
    foreach (SalesPlanStatus::cases() as $status) {
        expect($status->canTransitionTo($status))->toBeFalse();
    }
});
