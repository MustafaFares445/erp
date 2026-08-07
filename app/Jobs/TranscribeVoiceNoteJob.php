<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TranscriptionStatus;
use App\Enums\VoiceNoteStatus;
use App\Models\EmployeeVoiceNote;
use App\Models\VoiceNoteTranscription;
use App\Services\Employees\Data\TranscriptionRequest;
use App\Services\Employees\Exceptions\TranscriptionPayloadException;
use App\Services\Employees\KeywordDetectionService;
use App\Services\Employees\VoiceNoteTranscriber;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Queued transcription attempt (Principle V, research.md R-003). A
 * {@see TranscriptionPayloadException} (4xx caused by the payload itself) is
 * caught here and never retried; every other exception propagates so
 * Laravel's queue retries it up to {@see self::$tries} times with
 * {@see self::$backoff}. Failure — of either kind — never touches the
 * parent visit, a performance score, or a salary calculation.
 */
final class TranscribeVoiceNoteJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public int $voiceNoteTranscriptionId) {}

    public function handle(VoiceNoteTranscriber $transcriber): void
    {
        $transcription = VoiceNoteTranscription::query()->findOrFail($this->voiceNoteTranscriptionId);
        $voiceNote = $transcription->employeeVoiceNote;

        if (! $voiceNote instanceof EmployeeVoiceNote) {
            return;
        }

        $voiceNote->update(['status' => VoiceNoteStatus::Processing]);

        $media = $voiceNote->getFirstMedia('voice-note-audio');

        if (! $media instanceof Media) {
            $this->markFailed($transcription, $voiceNote, 'No audio file is attached to this voice note.');

            return;
        }

        try {
            $result = $transcriber->transcribe(new TranscriptionRequest(
                audioDisk: $media->disk,
                audioDiskPath: $media->getPathRelativeToRoot(),
                language: $voiceNote->language,
            ));
        } catch (TranscriptionPayloadException $transcriptionPayloadException) {
            $this->markFailed($transcription, $voiceNote, $transcriptionPayloadException->getMessage());

            return;
        }

        $transcription->update([
            'transcript' => $result->transcript,
            'confidence' => $result->confidence,
            'confidence_source' => $result->confidenceSource,
            'detected_language' => $result->detectedLanguage,
            'provider' => $result->provider,
            'status' => TranscriptionStatus::Succeeded,
        ]);

        $voiceNote->update(['status' => VoiceNoteStatus::Transcribed]);

        app(KeywordDetectionService::class)->detect($transcription->refresh());
    }

    public function failed(?Throwable $exception): void
    {
        $transcription = VoiceNoteTranscription::query()->find($this->voiceNoteTranscriptionId);

        if (! $transcription instanceof VoiceNoteTranscription) {
            return;
        }

        $voiceNote = $transcription->employeeVoiceNote;

        if ($voiceNote instanceof EmployeeVoiceNote) {
            $this->markFailed($transcription, $voiceNote, $exception?->getMessage() ?? 'Transcription failed.');
        }
    }

    private function markFailed(VoiceNoteTranscription $transcription, EmployeeVoiceNote $voiceNote, string $message): void
    {
        $transcription->update([
            'status' => TranscriptionStatus::Failed,
            'error_message' => $message,
        ]);

        $voiceNote->update(['status' => VoiceNoteStatus::Failed]);
    }
}
