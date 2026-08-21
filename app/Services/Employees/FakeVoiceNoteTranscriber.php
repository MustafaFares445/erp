<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Enums\TranscriptionConfidenceSource;
use App\Services\Employees\Data\TranscriptionRequest;
use App\Services\Employees\Data\TranscriptionResult;

/**
 * Deterministic driver used in every test and available locally
 * (`EMPLOYEES_TRANSCRIBE_DRIVER=fake`); the test environment forces this so
 * no test reaches the network (contracts/voice-note-ai.md).
 */
final class FakeVoiceNoteTranscriber implements VoiceNoteTranscriber
{
    public function transcribe(TranscriptionRequest $request): TranscriptionResult
    {
        return new TranscriptionResult(
            transcript: 'This is a fake transcript used for testing and local development.',
            confidence: 87.50,
            confidenceSource: TranscriptionConfidenceSource::ProviderReported,
            detectedLanguage: $request->language ?? 'en',
            provider: 'fake',
        );
    }
}
