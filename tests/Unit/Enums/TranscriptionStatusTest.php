<?php

declare(strict_types=1);

use App\Enums\TranscriptionStatus;

it('allows exactly the documented transitions', function (): void {
    expect(TranscriptionStatus::Pending->canTransitionTo(TranscriptionStatus::Succeeded))->toBeTrue()
        ->and(TranscriptionStatus::Pending->canTransitionTo(TranscriptionStatus::Failed))->toBeTrue()
        ->and(TranscriptionStatus::Failed->canTransitionTo(TranscriptionStatus::Pending))->toBeTrue();
});

it('rejects every undocumented transition', function (): void {
    expect(TranscriptionStatus::Succeeded->canTransitionTo(TranscriptionStatus::Pending))->toBeFalse()
        ->and(TranscriptionStatus::Succeeded->canTransitionTo(TranscriptionStatus::Failed))->toBeFalse()
        ->and(TranscriptionStatus::Failed->canTransitionTo(TranscriptionStatus::Succeeded))->toBeFalse();
});

it('rejects every self-transition', function (): void {
    foreach (TranscriptionStatus::cases() as $status) {
        expect($status->canTransitionTo($status))->toBeFalse();
    }
});
