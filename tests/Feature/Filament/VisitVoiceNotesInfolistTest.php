<?php

declare(strict_types=1);

use App\Filament\Resources\Visits\Pages\ViewVisit;
use App\Models\CustomerVisit;
use App\Models\EmployeeVoiceNote;
use App\Models\User;
use App\Models\VoiceNoteTranscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders voice notes as audio players instead of a table, without a transcription field', function (): void {
    $admin = User::factory()->admin()->create();
    $visit = CustomerVisit::factory()->create();

    $voiceNote = EmployeeVoiceNote::factory()
        ->for($visit, 'customerVisit')
        ->create(['language' => 'ar', 'duration_seconds' => 42]);
    $voiceNote->addMediaFromString('fake-audio-bytes')
        ->usingFileName('note.m4a')
        ->toMediaCollection('voice-note-audio', 'local');
    VoiceNoteTranscription::factory()
        ->for($voiceNote, 'employeeVoiceNote')
        ->transcribed()
        ->create(['transcript' => 'Client requested a callback.']);

    Livewire::actingAs($admin)
        ->test(ViewVisit::class, ['record' => $visit->getKey()])
        ->assertSuccessful()
        ->assertSee('Voice notes')
        ->assertSee('ar')
        ->assertSee('42s')
        ->assertSeeHtml('<audio')
        ->assertDontSee('Client requested a callback.')
        ->assertDontSeeHtml('<table');
});

it('shows an empty-state placeholder when a visit has no voice notes', function (): void {
    $admin = User::factory()->admin()->create();
    $visit = CustomerVisit::factory()->create();

    Livewire::actingAs($admin)
        ->test(ViewVisit::class, ['record' => $visit->getKey()])
        ->assertSuccessful()
        ->assertSee('No voice notes recorded for this visit.');
});
