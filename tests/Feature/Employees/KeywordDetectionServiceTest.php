<?php

declare(strict_types=1);

use App\Enums\SalesOpportunityStatus;
use App\Models\AiKeywordRule;
use App\Models\SalesOpportunity;
use App\Models\VoiceNoteTranscription;
use App\Services\Employees\KeywordDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates no draft for a null or blank transcript', function (): void {
    $transcription = VoiceNoteTranscription::factory()->create(['transcript' => null]);

    $drafts = app(KeywordDetectionService::class)->detect($transcription);

    expect($drafts)->toBeEmpty()
        ->and(SalesOpportunity::query()->count())->toBe(0);

    $blank = VoiceNoteTranscription::factory()->create(['transcript' => '   ']);

    expect(app(KeywordDetectionService::class)->detect($blank))->toBeEmpty();
});

it('creates a Draft opportunity for a transcript matching an active keyword rule', function (): void {
    AiKeywordRule::factory()->create(['keyword' => 'generator', 'is_active' => true]);
    $transcription = VoiceNoteTranscription::factory()->transcribed()->create([
        'transcript' => 'The customer asked about a backup Generator for the warehouse.',
    ]);

    $drafts = app(KeywordDetectionService::class)->detect($transcription);

    expect($drafts)->toHaveCount(1);
    $draft = $drafts->sole();
    expect($draft->status)->toBe(SalesOpportunityStatus::Draft)
        ->and($draft->voice_note_transcription_id)->toBe($transcription->id)
        ->and($draft->origin_summary)->toBe('The customer asked about a backup Generator for the warehouse.')
        ->and($draft->summary)->toContain('generator');
});

it('creates no draft when the transcript does not match any active rule', function (): void {
    AiKeywordRule::factory()->create(['keyword' => 'generator', 'is_active' => true]);
    AiKeywordRule::factory()->create(['keyword' => 'forklift', 'is_active' => false]);
    $transcription = VoiceNoteTranscription::factory()->create([
        'transcript' => 'The visit was routine, nothing further to report.',
    ]);

    expect(app(KeywordDetectionService::class)->detect($transcription))->toBeEmpty();
});
