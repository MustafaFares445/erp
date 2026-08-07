<?php

declare(strict_types=1);

use App\Filament\Resources\AiKeywordRules\AiKeywordRuleResource;
use App\Filament\Resources\OpportunityDrafts\OpportunityDraftResource;
use App\Filament\Resources\VoiceNotes\VoiceNoteResource;
use App\Models\AiKeywordRule;
use App\Models\EmployeeVoiceNote;
use App\Models\SalesOpportunityDraft;
use App\Models\User;
use App\Models\VoiceNoteTranscription;
use Database\Seeders\EmployeePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new EmployeePermissionSeeder)->run();
});

it('renders the voice note list and a transcribed record view without error', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $voiceNote = EmployeeVoiceNote::factory()->create();
    $voiceNote->addMediaFromString('fake-audio-bytes')->usingFileName('note.mp3')->toMediaCollection('voice-note-audio', 'local');
    VoiceNoteTranscription::factory()->for($voiceNote, 'employeeVoiceNote')->transcribed()->create();

    $this->actingAs($admin)->get(VoiceNoteResource::getUrl('index'))->assertOk();
    $this->actingAs($admin)->get(VoiceNoteResource::getUrl('view', ['record' => $voiceNote]))->assertOk();
});

it('renders the AI keyword rule list, create, and edit pages without error', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $rule = AiKeywordRule::factory()->create();

    $this->actingAs($admin)->get(AiKeywordRuleResource::getUrl('index'))->assertOk();
    $this->actingAs($admin)->get(AiKeywordRuleResource::getUrl('create'))->assertOk();
    $this->actingAs($admin)->get(AiKeywordRuleResource::getUrl('edit', ['record' => $rule]))->assertOk();
});

it('renders the opportunity draft list and view pages without error', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $draft = SalesOpportunityDraft::factory()->create();

    $this->actingAs($admin)->get(OpportunityDraftResource::getUrl('index'))->assertOk();
    $this->actingAs($admin)->get(OpportunityDraftResource::getUrl('view', ['record' => $draft]))->assertOk();
});
