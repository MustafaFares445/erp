<?php

declare(strict_types=1);

use App\Enums\TranscriptionConfidenceSource;
use App\Jobs\TranscribeVoiceNoteJob;
use App\Models\EmployeeVoiceNote;
use App\Models\VoiceNoteTranscription;
use App\Services\Employees\Data\TranscriptionRequest;
use App\Services\Employees\Data\TranscriptionResult;
use App\Services\Employees\VoiceNoteTranscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('omits the language from the transcription request when the voice note has none', function (): void {
    $voiceNote = EmployeeVoiceNote::factory()->create(['language' => null]);
    $voiceNote->addMediaFromString('fake-audio-bytes')->usingFileName('note.mp3')->toMediaCollection('voice-note-audio', 'local');
    $transcription = VoiceNoteTranscription::factory()->for($voiceNote, 'employeeVoiceNote')->create();

    $spy = new class implements VoiceNoteTranscriber
    {
        public ?TranscriptionRequest $captured = null;

        public function transcribe(TranscriptionRequest $request): TranscriptionResult
        {
            $this->captured = $request;

            return new TranscriptionResult('hi', 90.0, TranscriptionConfidenceSource::ProviderReported, 'fr', 'fake');
        }
    };

    new TranscribeVoiceNoteJob($transcription->id)->handle($spy);

    expect($spy->captured?->language)->toBeNull()
        ->and($transcription->fresh()->detected_language)->toBe('fr');
});

it('passes the language to the request when the voice note has one set', function (): void {
    $voiceNote = EmployeeVoiceNote::factory()->create(['language' => 'ar']);
    $voiceNote->addMediaFromString('fake-audio-bytes')->usingFileName('note.mp3')->toMediaCollection('voice-note-audio', 'local');
    $transcription = VoiceNoteTranscription::factory()->for($voiceNote, 'employeeVoiceNote')->create();

    $spy = new class implements VoiceNoteTranscriber
    {
        public ?TranscriptionRequest $captured = null;

        public function transcribe(TranscriptionRequest $request): TranscriptionResult
        {
            $this->captured = $request;

            return new TranscriptionResult('marhaba', 80.0, TranscriptionConfidenceSource::ProviderReported, 'ar', 'fake');
        }
    };

    new TranscribeVoiceNoteJob($transcription->id)->handle($spy);

    expect($spy->captured?->language)->toBe('ar')
        ->and($transcription->fresh()->detected_language)->toBe('ar');
});
