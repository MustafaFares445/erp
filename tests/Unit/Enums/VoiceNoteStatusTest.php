<?php

declare(strict_types=1);

use App\Enums\VoiceNoteStatus;

it('allows exactly the documented transitions', function (): void {
    expect(VoiceNoteStatus::Pending->canTransitionTo(VoiceNoteStatus::Processing))->toBeTrue()
        ->and(VoiceNoteStatus::Processing->canTransitionTo(VoiceNoteStatus::Transcribed))->toBeTrue()
        ->and(VoiceNoteStatus::Processing->canTransitionTo(VoiceNoteStatus::Failed))->toBeTrue()
        ->and(VoiceNoteStatus::Failed->canTransitionTo(VoiceNoteStatus::Pending))->toBeTrue();
});

it('rejects every undocumented transition', function (): void {
    expect(VoiceNoteStatus::Pending->canTransitionTo(VoiceNoteStatus::Transcribed))->toBeFalse()
        ->and(VoiceNoteStatus::Processing->canTransitionTo(VoiceNoteStatus::Pending))->toBeFalse()
        ->and(VoiceNoteStatus::Transcribed->canTransitionTo(VoiceNoteStatus::Pending))->toBeFalse()
        ->and(VoiceNoteStatus::Transcribed->canTransitionTo(VoiceNoteStatus::Processing))->toBeFalse()
        ->and(VoiceNoteStatus::Transcribed->canTransitionTo(VoiceNoteStatus::Failed))->toBeFalse();
});

it('rejects every self-transition', function (): void {
    foreach (VoiceNoteStatus::cases() as $status) {
        expect($status->canTransitionTo($status))->toBeFalse();
    }
});
