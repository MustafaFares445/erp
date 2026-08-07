<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Services\Employees\Data\TranscriptionRequest;
use App\Services\Employees\Data\TranscriptionResult;
use App\Services\Employees\Exceptions\TranscriptionPayloadException;
use App\Services\Employees\Exceptions\TranscriptionTransportException;

/**
 * The provider boundary (D6). Implemented by {@see OpenAiWhisperTranscriber}
 * (production) and {@see FakeVoiceNoteTranscriber} (test/local, forced by
 * `EMPLOYEES_TRANSCRIBE_DRIVER`). No class outside this driver namespace may
 * reference the OpenAI client (contracts/voice-note-ai.md; enforced by
 * `tests/Unit/ArchTest.php`).
 */
interface VoiceNoteTranscriber
{
    /**
     * @throws TranscriptionTransportException on a retryable failure (transport, timeout, 429, 5xx)
     * @throws TranscriptionPayloadException on a non-retryable payload failure (4xx)
     */
    public function transcribe(TranscriptionRequest $request): TranscriptionResult;
}
