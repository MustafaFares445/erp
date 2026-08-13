<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Enums\TranscriptionConfidenceSource;
use App\Enums\TranscriptionStatus;
use App\Enums\VoiceNoteStatus;
use App\Jobs\TranscribeVoiceNoteJob;
use App\Models\CustomerVisit;
use App\Models\EmployeeVoiceNote;
use App\Models\VoiceNoteTranscription;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Attaches audio to a visit and starts transcription (Principle V): stores
 * the recording, enforces the max-bytes guard before anything is dispatched,
 * creates the `Pending` transcription row, and queues the job. Never blocks
 * or reverses the visit itself.
 */
final readonly class VoiceNoteIntakeService
{
    public function intake(
        CustomerVisit $visit,
        string $audioDiskPath,
        string $originalFilename,
        ?string $language = null,
        ?int $durationSeconds = null,
    ): EmployeeVoiceNote {
        $this->assertWithinMaxBytes($audioDiskPath);

        return DB::transaction(function () use ($visit, $audioDiskPath, $originalFilename, $language, $durationSeconds): EmployeeVoiceNote {
            $voiceNote = EmployeeVoiceNote::query()->create([
                'customer_visit_id' => $visit->id,
                'employee_id' => $visit->employee_id,
                'language' => $language,
                'duration_seconds' => $durationSeconds,
                'status' => VoiceNoteStatus::Pending,
            ]);

            $voiceNote->addMediaFromDisk($audioDiskPath, 'local')
                ->usingFileName($originalFilename)
                ->toMediaCollection('voice-note-audio', 'local');

            $transcription = VoiceNoteTranscription::query()->create([
                'employee_voice_note_id' => $voiceNote->id,
                'confidence_source' => TranscriptionConfidenceSource::Unavailable,
                'status' => TranscriptionStatus::Pending,
            ]);

            activity()
                ->performedOn($voiceNote)
                ->withChanges([
                    'attributes' => $voiceNote->getAttributes(),
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('voice_note.created');

            TranscribeVoiceNoteJob::dispatch($transcription->id);

            return $voiceNote;
        });
    }

    private function assertWithinMaxBytes(string $audioDiskPath): void
    {
        $configured = config('employees.transcription.max_bytes', 26214400);
        $maxBytes = is_numeric($configured) ? (int) $configured : 26214400;

        if (Storage::disk('local')->size($audioDiskPath) > $maxBytes) {
            throw new DomainException(__('admin.employees.errors.audio_too_large'));
        }
    }
}
