<?php

declare(strict_types=1);

use App\Enums\VisitStatus;

it('allows exactly the documented transitions', function (): void {
    expect(VisitStatus::Scheduled->canTransitionTo(VisitStatus::EnRoute))->toBeTrue()
        ->and(VisitStatus::Scheduled->canTransitionTo(VisitStatus::UnableToComplete))->toBeTrue()
        ->and(VisitStatus::Scheduled->canTransitionTo(VisitStatus::Cancelled))->toBeTrue()
        ->and(VisitStatus::EnRoute->canTransitionTo(VisitStatus::InProgress))->toBeTrue()
        ->and(VisitStatus::EnRoute->canTransitionTo(VisitStatus::UnableToComplete))->toBeTrue()
        ->and(VisitStatus::InProgress->canTransitionTo(VisitStatus::Completed))->toBeTrue()
        ->and(VisitStatus::InProgress->canTransitionTo(VisitStatus::UnableToComplete))->toBeTrue();
});

it('rejects every undocumented transition', function (): void {
    expect(VisitStatus::Scheduled->canTransitionTo(VisitStatus::InProgress))->toBeFalse()
        ->and(VisitStatus::Scheduled->canTransitionTo(VisitStatus::Completed))->toBeFalse()
        ->and(VisitStatus::EnRoute->canTransitionTo(VisitStatus::Completed))->toBeFalse()
        ->and(VisitStatus::Completed->canTransitionTo(VisitStatus::InProgress))->toBeFalse()
        ->and(VisitStatus::Completed->canTransitionTo(VisitStatus::UnableToComplete))->toBeFalse()
        ->and(VisitStatus::UnableToComplete->canTransitionTo(VisitStatus::Scheduled))->toBeFalse()
        ->and(VisitStatus::UnableToComplete->canTransitionTo(VisitStatus::Completed))->toBeFalse();
});

it('rejects every self-transition', function (): void {
    foreach (VisitStatus::cases() as $status) {
        expect($status->canTransitionTo($status))->toBeFalse();
    }
});

it('keeps completion prerequisites in the lifecycle service rather than the enum graph', function (): void {
    expect(VisitStatus::InProgress->canTransitionTo(VisitStatus::Completed))->toBeTrue();
});
