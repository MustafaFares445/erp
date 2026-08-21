<?php

declare(strict_types=1);

namespace App\Services\Employees\Data;

use App\Services\Employees\VoiceNoteTranscriber;
use Spatie\LaravelData\Data;

/**
 * Input to a {@see VoiceNoteTranscriber} (D6,
 * contracts/voice-note-ai.md). `language` is omitted (left `null`) unless the
 * voice note has an explicit language hint, so the provider can auto-detect
 * (FR-055).
 */
final class TranscriptionRequest extends Data
{
    public function __construct(
        public string $audioDisk,
        public string $audioDiskPath,
        public ?string $language = null,
    ) {}
}
