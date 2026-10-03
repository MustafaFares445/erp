<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\TranscriptionConfidenceSource;
use App\Services\Employees\Data\TranscriptionRequest;
use App\Services\Employees\Data\TranscriptionResult;
use App\Services\Employees\Exceptions\TranscriptionPayloadException;
use App\Services\Employees\VoiceNoteTranscriber;

/**
 * Offline transcriber for the demo month. Every recording is a placeholder file whose
 * *name* selects a scripted transcript, so the production pipeline (intake, queued job,
 * keyword detection) runs unchanged while nothing ever reaches a speech provider.
 * A file name containing "corrupt" simulates an unreadable recording.
 */
final readonly class DemoScriptedTranscriber implements VoiceNoteTranscriber
{
    /** @param array<string, array{text: string, confidence: float, language: string}> $script keyed by file name */
    public function __construct(private array $script) {}

    public function transcribe(TranscriptionRequest $request): TranscriptionResult
    {
        $name = basename($request->audioDiskPath);

        if (str_contains($name, 'corrupt')) {
            throw new TranscriptionPayloadException('The audio file could not be decoded (unsupported or damaged recording).');
        }

        $entry = $this->script[$name] ?? throw new TranscriptionPayloadException("No scripted transcript for [{$name}].");

        return new TranscriptionResult(
            transcript: $entry['text'],
            confidence: $entry['confidence'],
            confidenceSource: TranscriptionConfidenceSource::ProviderReported,
            detectedLanguage: $entry['language'],
            provider: 'demo-scripted',
        );
    }
}
