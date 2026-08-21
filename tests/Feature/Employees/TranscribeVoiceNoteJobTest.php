<?php

declare(strict_types=1);

use App\Enums\TranscriptionConfidenceSource;
use App\Enums\TranscriptionStatus;
use App\Enums\VoiceNoteStatus;
use App\Jobs\TranscribeVoiceNoteJob;
use App\Models\EmployeeVoiceNote;
use App\Models\VoiceNoteTranscription;
use App\Services\Employees\Data\TranscriptionRequest;
use App\Services\Employees\Data\TranscriptionResult;
use App\Services\Employees\Exceptions\TranscriptionPayloadException;
use App\Services\Employees\Exceptions\TranscriptionTransportException;
use App\Services\Employees\VoiceNoteTranscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('configures bounded retries with backoff', function (): void {
    $job = new TranscribeVoiceNoteJob(1);

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([60, 300]);
});

it('never retries a 4xx payload failure: it is caught and written as Failed immediately', function (): void {
    $voiceNote = EmployeeVoiceNote::factory()->create();
    $voiceNote->addMediaFromString('fake-audio-bytes')->usingFileName('note.mp3')->toMediaCollection('voice-note-audio', 'local');
    $transcription = VoiceNoteTranscription::factory()->for($voiceNote, 'employeeVoiceNote')->create();

    $transcriber = new class implements VoiceNoteTranscriber
    {
        public function transcribe(TranscriptionRequest $request): TranscriptionResult
        {
            throw new TranscriptionPayloadException('Unsupported audio format.');
        }
    };

    new TranscribeVoiceNoteJob($transcription->id)->handle($transcriber);

    expect($transcription->fresh()->status)->toBe(TranscriptionStatus::Failed)
        ->and($transcription->fresh()->error_message)->toBe('Unsupported audio format.')
        ->and($voiceNote->fresh()->status)->toBe(VoiceNoteStatus::Failed);
});

it('lets a transport failure propagate uncaught, so the queue worker can retry it', function (): void {
    $voiceNote = EmployeeVoiceNote::factory()->create();
    $voiceNote->addMediaFromString('fake-audio-bytes')->usingFileName('note.mp3')->toMediaCollection('voice-note-audio', 'local');
    $transcription = VoiceNoteTranscription::factory()->for($voiceNote, 'employeeVoiceNote')->create();

    $transcriber = new class implements VoiceNoteTranscriber
    {
        public function transcribe(TranscriptionRequest $request): TranscriptionResult
        {
            throw new TranscriptionTransportException('HTTP 503 from provider.');
        }
    };

    expect(fn () => new TranscribeVoiceNoteJob($transcription->id)->handle($transcriber))
        ->toThrow(TranscriptionTransportException::class);

    expect($transcription->fresh()->status)->toBe(TranscriptionStatus::Pending);
});

it('writes Failed via the failed() hook once the queue exhausts its retries', function (): void {
    $voiceNote = EmployeeVoiceNote::factory()->create();
    $voiceNote->addMediaFromString('fake-audio-bytes')->usingFileName('note.mp3')->toMediaCollection('voice-note-audio', 'local');
    $transcription = VoiceNoteTranscription::factory()->for($voiceNote, 'employeeVoiceNote')->create();

    new TranscribeVoiceNoteJob($transcription->id)->failed(new TranscriptionTransportException('HTTP 503 from provider.'));

    expect($transcription->fresh()->status)->toBe(TranscriptionStatus::Failed)
        ->and($transcription->fresh()->error_message)->toBe('HTTP 503 from provider.')
        ->and($voiceNote->fresh()->status)->toBe(VoiceNoteStatus::Failed)
        ->and($transcription->fresh()->confidence_source)->toBe(TranscriptionConfidenceSource::Unavailable);
});

it('marks the voice note Failed when no audio is attached, without calling the transcriber', function (): void {
    $voiceNote = EmployeeVoiceNote::factory()->create();
    $transcription = VoiceNoteTranscription::factory()->for($voiceNote, 'employeeVoiceNote')->create();

    $transcriber = new class implements VoiceNoteTranscriber
    {
        public bool $called = false;

        public function transcribe(TranscriptionRequest $request): TranscriptionResult
        {
            $this->called = true;

            throw new RuntimeException('should never be reached');
        }
    };

    new TranscribeVoiceNoteJob($transcription->id)->handle($transcriber);

    expect($transcriber->called)->toBeFalse()
        ->and($transcription->fresh()->status)->toBe(TranscriptionStatus::Failed)
        ->and($voiceNote->fresh()->status)->toBe(VoiceNoteStatus::Failed);
});

it('returns quietly from handle() when the linked voice note has been soft-deleted', function (): void {
    $voiceNote = EmployeeVoiceNote::factory()->create();
    $transcription = VoiceNoteTranscription::factory()->for($voiceNote, 'employeeVoiceNote')->create();
    $voiceNote->delete();

    $transcriber = new class implements VoiceNoteTranscriber
    {
        public bool $called = false;

        public function transcribe(TranscriptionRequest $request): TranscriptionResult
        {
            $this->called = true;

            throw new RuntimeException('should never be reached');
        }
    };

    new TranscribeVoiceNoteJob($transcription->id)->handle($transcriber);

    expect($transcriber->called)->toBeFalse()
        ->and($transcription->fresh()->status)->toBe(TranscriptionStatus::Pending);
});

it('returns quietly from failed() when the transcription record cannot be found', function (): void {
    new TranscribeVoiceNoteJob(999999)->failed(new RuntimeException('Transcription failed.'));

    expect(VoiceNoteTranscription::query()->count())->toBe(0);
});

it('persists a successful transcription and moves both rows to their terminal success status', function (): void {
    $voiceNote = EmployeeVoiceNote::factory()->create();
    $voiceNote->addMediaFromString('fake-audio-bytes')->usingFileName('note.mp3')->toMediaCollection('voice-note-audio', 'local');
    $transcription = VoiceNoteTranscription::factory()->for($voiceNote, 'employeeVoiceNote')->create();

    $transcriber = new class implements VoiceNoteTranscriber
    {
        public function transcribe(TranscriptionRequest $request): TranscriptionResult
        {
            return new TranscriptionResult(
                transcript: 'Customer asked about pricing.',
                confidence: 91.25,
                confidenceSource: TranscriptionConfidenceSource::ProviderReported,
                detectedLanguage: 'en',
                provider: 'fake',
            );
        }
    };

    new TranscribeVoiceNoteJob($transcription->id)->handle($transcriber);

    expect($transcription->fresh()->status)->toBe(TranscriptionStatus::Succeeded)
        ->and($transcription->fresh()->transcript)->toBe('Customer asked about pricing.')
        ->and((float) $transcription->fresh()->confidence)->toBe(91.25)
        ->and($voiceNote->fresh()->status)->toBe(VoiceNoteStatus::Transcribed);
});
