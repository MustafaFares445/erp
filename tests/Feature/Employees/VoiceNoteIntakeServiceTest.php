<?php

declare(strict_types=1);

use App\Jobs\TranscribeVoiceNoteJob;
use App\Models\CustomerVisit;
use App\Models\EmployeeVoiceNote;
use App\Services\Employees\VoiceNoteIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('rejects oversized audio before dispatching the job', function (): void {
    Bus::fake();
    Storage::fake('local');
    config(['employees.transcription.max_bytes' => 10]);
    Storage::disk('local')->put('tmp/big.mp3', str_repeat('a', 20));

    $visit = CustomerVisit::factory()->create();

    expect(fn () => app(VoiceNoteIntakeService::class)->intake($visit, 'tmp/big.mp3', 'big.mp3'))
        ->toThrow(DomainException::class);

    Bus::assertNotDispatched(TranscribeVoiceNoteJob::class);
    expect(EmployeeVoiceNote::query()->count())->toBe(0);
});

it('accepts audio within the size limit, stores it, and dispatches the transcription job', function (): void {
    Bus::fake();
    Storage::fake('local');
    config(['employees.transcription.max_bytes' => 1000]);
    Storage::disk('local')->put('tmp/ok.mp3', str_repeat('a', 20));

    $visit = CustomerVisit::factory()->create();

    $voiceNote = app(VoiceNoteIntakeService::class)->intake($visit, 'tmp/ok.mp3', 'ok.mp3', 'en', 42);

    Bus::assertDispatched(TranscribeVoiceNoteJob::class);
    expect($voiceNote->employee_id)->toBe($visit->employee_id)
        ->and($voiceNote->customer_visit_id)->toBe($visit->id)
        ->and($voiceNote->language)->toBe('en')
        ->and($voiceNote->duration_seconds)->toBe(42)
        ->and($voiceNote->getFirstMedia('voice-note-audio'))->not->toBeNull();
});
