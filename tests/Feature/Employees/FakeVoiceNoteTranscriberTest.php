<?php

declare(strict_types=1);

use App\Enums\TranscriptionConfidenceSource;
use App\Services\Employees\Data\TranscriptionRequest;
use App\Services\Employees\FakeVoiceNoteTranscriber;

it('returns a deterministic transcript with a provider-reported confidence', function (): void {
    $result = (new FakeVoiceNoteTranscriber)->transcribe(new TranscriptionRequest('local', 'voice-notes/note.mp3', 'ar'));

    expect($result->transcript)->not->toBe('')
        ->and($result->confidence)->toBe(87.50)
        ->and($result->confidenceSource)->toBe(TranscriptionConfidenceSource::ProviderReported)
        ->and($result->detectedLanguage)->toBe('ar')
        ->and($result->provider)->toBe('fake');
});

it('defaults the detected language to English when none was requested', function (): void {
    $result = (new FakeVoiceNoteTranscriber)->transcribe(new TranscriptionRequest('local', 'voice-notes/note.mp3'));

    expect($result->detectedLanguage)->toBe('en');
});
