<?php

declare(strict_types=1);

namespace App\Services\Employees\Data;

use App\Enums\TranscriptionConfidenceSource;
use App\Models\VoiceNoteTranscription;
use App\Services\Employees\VoiceNoteTranscriber;
use Spatie\LaravelData\Data;

/**
 * Output of a {@see VoiceNoteTranscriber} (D6,
 * contracts/voice-note-ai.md). `confidence` is non-null exactly when
 * `confidenceSource` is `ProviderReported` or `DerivedFromLogProb` — the
 * same invariant {@see VoiceNoteTranscription} enforces on save.
 */
final class TranscriptionResult extends Data
{
    public function __construct(
        public string $transcript,
        public ?float $confidence,
        public TranscriptionConfidenceSource $confidenceSource,
        public ?string $detectedLanguage,
        public string $provider,
    ) {}
}
