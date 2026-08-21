<?php

declare(strict_types=1);

use App\Enums\PlanTaskStatus;

it('allows exactly the documented transitions', function (): void {
    expect(PlanTaskStatus::Pending->canTransitionTo(PlanTaskStatus::InProgress))->toBeTrue()
        ->and(PlanTaskStatus::Pending->canTransitionTo(PlanTaskStatus::Completed))->toBeTrue()
        ->and(PlanTaskStatus::Pending->canTransitionTo(PlanTaskStatus::Cancelled))->toBeTrue()
        ->and(PlanTaskStatus::InProgress->canTransitionTo(PlanTaskStatus::Completed))->toBeTrue()
        ->and(PlanTaskStatus::InProgress->canTransitionTo(PlanTaskStatus::Cancelled))->toBeTrue()
        ->and(PlanTaskStatus::InProgress->canTransitionTo(PlanTaskStatus::Pending))->toBeTrue()
        ->and(PlanTaskStatus::Completed->canTransitionTo(PlanTaskStatus::InProgress))->toBeTrue()
        ->and(PlanTaskStatus::Cancelled->canTransitionTo(PlanTaskStatus::Pending))->toBeTrue();
});

it('rejects every undocumented transition', function (): void {
    expect(PlanTaskStatus::Completed->canTransitionTo(PlanTaskStatus::Cancelled))->toBeFalse()
        ->and(PlanTaskStatus::Cancelled->canTransitionTo(PlanTaskStatus::Completed))->toBeFalse()
        ->and(PlanTaskStatus::Cancelled->canTransitionTo(PlanTaskStatus::InProgress))->toBeFalse();
});

it('rejects every self-transition', function (): void {
    foreach (PlanTaskStatus::cases() as $status) {
        expect($status->canTransitionTo($status))->toBeFalse();
    }
});
