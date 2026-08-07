<?php

declare(strict_types=1);

use App\Enums\TranscriptionConfidenceSource;
use App\Enums\VisitStatus;
use App\Jobs\TranscribeVoiceNoteJob;
use App\Models\CustomerVisit;
use App\Models\EmployeeVoiceNote;
use App\Models\VoiceNoteTranscription;
use App\Services\Employees\Data\TranscriptionRequest;
use App\Services\Employees\Data\TranscriptionResult;
use App\Services\Employees\VoiceNoteTranscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('leaves the parent visit completely untouched when the transcriber throws', function (): void {
    $visit = CustomerVisit::factory()->completed()->create(['outcome' => 'Order placed']);
    $voiceNote = EmployeeVoiceNote::factory()->for($visit, 'customerVisit')->create();
    $voiceNote->addMediaFromString('fake-audio-bytes')->usingFileName('note.mp3')->toMediaCollection('voice-note-audio', 'local');
    $transcription = VoiceNoteTranscription::factory()->for($voiceNote, 'employeeVoiceNote')->create();

    $throwingTranscriber = new class implements VoiceNoteTranscriber
    {
        public function transcribe(TranscriptionRequest $request): TranscriptionResult
        {
            throw new RuntimeException('provider unreachable');
        }
    };

    try {
        new TranscribeVoiceNoteJob($transcription->id)->handle($throwingTranscriber);
    } catch (RuntimeException) {
        // expected: a retryable failure is left to propagate to the queue worker.
    }

    expect($visit->fresh()->status)->toBe(VisitStatus::Completed)
        ->and($visit->fresh()->outcome)->toBe('Order placed')
        ->and($visit->fresh()->checked_out_at)->not->toBeNull();
});

it('never fabricates a confidence value when a transcriber failure is later recorded', function (): void {
    $voiceNote = EmployeeVoiceNote::factory()->create();
    $voiceNote->addMediaFromString('fake-audio-bytes')->usingFileName('note.mp3')->toMediaCollection('voice-note-audio', 'local');
    $transcription = VoiceNoteTranscription::factory()->for($voiceNote, 'employeeVoiceNote')->create();

    new TranscribeVoiceNoteJob($transcription->id)->failed(new RuntimeException('exhausted retries'));

    expect($transcription->fresh()->confidence)->toBeNull()
        ->and($transcription->fresh()->confidence_source)->toBe(TranscriptionConfidenceSource::Unavailable);
});
