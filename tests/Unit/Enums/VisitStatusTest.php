<?php

declare(strict_types=1);

use App\Enums\VisitStatus;
use Illuminate\Support\Carbon;

it('allows exactly the documented transitions', function (): void {
    expect(VisitStatus::Planned->canTransitionTo(VisitStatus::InProgress))->toBeTrue()
        ->and(VisitStatus::Planned->canTransitionTo(VisitStatus::Missed))->toBeTrue()
        ->and(VisitStatus::InProgress->canTransitionTo(VisitStatus::Missed))->toBeTrue()
        ->and(VisitStatus::InProgress->canTransitionTo(VisitStatus::Completed, Carbon::now()))->toBeTrue()
        ->and(VisitStatus::Missed->canTransitionTo(VisitStatus::Planned))->toBeTrue();
});

it('rejects every undocumented transition', function (): void {
    expect(VisitStatus::Planned->canTransitionTo(VisitStatus::Completed, Carbon::now()))->toBeFalse()
        ->and(VisitStatus::Completed->canTransitionTo(VisitStatus::InProgress))->toBeFalse()
        ->and(VisitStatus::Completed->canTransitionTo(VisitStatus::Missed))->toBeFalse()
        ->and(VisitStatus::Missed->canTransitionTo(VisitStatus::InProgress))->toBeFalse()
        ->and(VisitStatus::Missed->canTransitionTo(VisitStatus::Completed, Carbon::now()))->toBeFalse();
});

it('rejects every self-transition', function (): void {
    foreach (VisitStatus::cases() as $status) {
        expect($status->canTransitionTo($status))->toBeFalse();
    }
});

it('requires a checked-out timestamp before InProgress can move to Completed', function (): void {
    expect(VisitStatus::InProgress->canTransitionTo(VisitStatus::Completed))->toBeFalse()
        ->and(VisitStatus::InProgress->canTransitionTo(VisitStatus::Completed, Carbon::now()))->toBeTrue();
});
