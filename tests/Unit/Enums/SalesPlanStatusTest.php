<?php

declare(strict_types=1);

use App\Enums\SalesPlanStatus;

it('allows exactly the operational plan transitions', function (): void {
    expect(SalesPlanStatus::Draft->canTransitionTo(SalesPlanStatus::Published))->toBeTrue()
        ->and(SalesPlanStatus::Draft->canTransitionTo(SalesPlanStatus::Archived))->toBeTrue()
        ->and(SalesPlanStatus::Published->canTransitionTo(SalesPlanStatus::InProgress))->toBeTrue()
        ->and(SalesPlanStatus::Published->canTransitionTo(SalesPlanStatus::Archived))->toBeTrue()
        ->and(SalesPlanStatus::InProgress->canTransitionTo(SalesPlanStatus::Completed))->toBeTrue()
        ->and(SalesPlanStatus::InProgress->canTransitionTo(SalesPlanStatus::Archived))->toBeTrue()
        ->and(SalesPlanStatus::Completed->canTransitionTo(SalesPlanStatus::Archived))->toBeTrue();
});

it('rejects undocumented plan transitions', function (): void {
    expect(SalesPlanStatus::Draft->canTransitionTo(SalesPlanStatus::InProgress))->toBeFalse()
        ->and(SalesPlanStatus::Draft->canTransitionTo(SalesPlanStatus::Completed))->toBeFalse()
        ->and(SalesPlanStatus::Published->canTransitionTo(SalesPlanStatus::Completed))->toBeFalse()
        ->and(SalesPlanStatus::InProgress->canTransitionTo(SalesPlanStatus::Draft))->toBeFalse()
        ->and(SalesPlanStatus::Completed->canTransitionTo(SalesPlanStatus::Published))->toBeFalse()
        ->and(SalesPlanStatus::Archived->canTransitionTo(SalesPlanStatus::Draft))->toBeFalse()
        ->and(SalesPlanStatus::Archived->canTransitionTo(SalesPlanStatus::Published))->toBeFalse()
        ->and(SalesPlanStatus::Archived->canTransitionTo(SalesPlanStatus::InProgress))->toBeFalse();
});

it('rejects every self-transition', function (): void {
    foreach (SalesPlanStatus::cases() as $status) {
        expect($status->canTransitionTo($status))->toBeFalse();
    }
});
