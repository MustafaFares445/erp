<?php

declare(strict_types=1);

use App\Enums\SalaryCalculationStatus;

it('allows exactly the documented transitions', function (): void {
    expect(SalaryCalculationStatus::Draft->canTransitionTo(SalaryCalculationStatus::PendingConfirmation))->toBeTrue()
        ->and(SalaryCalculationStatus::PendingConfirmation->canTransitionTo(SalaryCalculationStatus::Confirmed))->toBeTrue()
        ->and(SalaryCalculationStatus::PendingConfirmation->canTransitionTo(SalaryCalculationStatus::Superseded))->toBeTrue()
        ->and(SalaryCalculationStatus::Confirmed->canTransitionTo(SalaryCalculationStatus::Superseded))->toBeTrue();
});

it('rejects every undocumented transition', function (): void {
    expect(SalaryCalculationStatus::Draft->canTransitionTo(SalaryCalculationStatus::Confirmed))->toBeFalse()
        ->and(SalaryCalculationStatus::Confirmed->canTransitionTo(SalaryCalculationStatus::Draft))->toBeFalse()
        ->and(SalaryCalculationStatus::Confirmed->canTransitionTo(SalaryCalculationStatus::PendingConfirmation))->toBeFalse()
        ->and(SalaryCalculationStatus::Superseded->canTransitionTo(SalaryCalculationStatus::Draft))->toBeFalse()
        ->and(SalaryCalculationStatus::Superseded->canTransitionTo(SalaryCalculationStatus::PendingConfirmation))->toBeFalse()
        ->and(SalaryCalculationStatus::Superseded->canTransitionTo(SalaryCalculationStatus::Confirmed))->toBeFalse();
});

it('rejects every self-transition', function (): void {
    foreach (SalaryCalculationStatus::cases() as $status) {
        expect($status->canTransitionTo($status))->toBeFalse();
    }
});
