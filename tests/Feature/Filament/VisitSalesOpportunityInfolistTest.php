<?php

declare(strict_types=1);

use App\Filament\Resources\Visits\Pages\ViewVisit;
use App\Models\CustomerVisit;
use App\Models\EmployeeVoiceNote;
use App\Models\SalesOpportunity;
use App\Models\User;
use App\Models\VoiceNoteTranscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows a sales opportunity detected from the visit voice notes', function (): void {
    $admin = User::factory()->admin()->create();
    $visit = CustomerVisit::factory()->create();

    $voiceNote = EmployeeVoiceNote::factory()->for($visit, 'customerVisit')->create();
    $transcription = VoiceNoteTranscription::factory()->for($voiceNote, 'employeeVoiceNote')->create();
    SalesOpportunity::factory()
        ->for($transcription, 'transcription')
        ->create(['summary' => 'Client interested in a Form 4B upgrade.']);

    Livewire::actingAs($admin)
        ->test(ViewVisit::class, ['record' => $visit->getKey()])
        ->assertSuccessful()
        ->assertSee('Sales Opportunity')
        ->assertSee('Client interested in a Form 4B upgrade.');
});

it('shows an empty-state placeholder when a visit has no sales opportunity', function (): void {
    $admin = User::factory()->admin()->create();
    $visit = CustomerVisit::factory()->create();

    Livewire::actingAs($admin)
        ->test(ViewVisit::class, ['record' => $visit->getKey()])
        ->assertSuccessful()
        ->assertSee('No sales opportunity detected for this visit');
});
