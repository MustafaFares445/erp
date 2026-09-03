<?php

declare(strict_types=1);

use App\Models\AiKeywordRule;
use App\Models\Quotation;
use App\Models\SalesOpportunity;
use App\Models\User;
use App\Models\VoiceNoteTranscription;
use App\Services\Employees\KeywordDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('snapshots transcript evidence when an AI opportunity is created', function (): void {
    $transcription = VoiceNoteTranscription::factory()->transcribed()->create([
        'transcript' => 'The customer asked for an infusion pump quotation.',
    ]);
    AiKeywordRule::factory()->create([
        'keyword' => 'pump',
        'is_active' => true,
    ]);

    $opportunity = app(KeywordDetectionService::class)
        ->detect($transcription)
        ->sole();

    expect($opportunity->origin_summary)
        ->toBe('The customer asked for an infusion pump quotation.')
        ->and($opportunity->isAiOriginated())
        ->toBeTrue();
});

it('preserves reviewed opportunity evidence when the source transcription is deleted', function (): void {
    $transcription = VoiceNoteTranscription::factory()->transcribed()->create([
        'transcript' => 'Customer expressed interest in the service package.',
    ]);
    $reviewer = User::factory()->admin()->create();

    $opportunity = SalesOpportunity::factory()->create([
        'voice_note_transcription_id' => $transcription->getKey(),
        'origin_summary' => (string) $transcription->transcript,
        'reviewed_by' => $reviewer->getKey(),
        'reviewed_at' => now(),
        'review_notes' => 'Approved after human review.',
    ]);

    $quotation = Quotation::factory()->create([
        'sales_opportunity_id' => $opportunity->getKey(),
    ]);

    $transcription->delete();

    $fresh = $opportunity->fresh();

    expect($fresh)->not->toBeNull()
        ->and($fresh?->voice_note_transcription_id)->toBeNull()
        ->and($fresh?->origin_summary)->toBe('Customer expressed interest in the service package.')
        ->and($fresh?->reviewed_by)->toBe($reviewer->getKey())
        ->and($fresh?->review_notes)->toBe('Approved after human review.')
        ->and($fresh?->isAiOriginated())->toBeTrue()
        ->and($quotation->fresh()?->sales_opportunity_id)->toBe($opportunity->getKey());
});
