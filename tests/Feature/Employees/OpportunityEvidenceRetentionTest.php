<?php

declare(strict_types=1);

use App\Enums\SalesOpportunityStatus;
use App\Filament\Resources\SalesOpportunities\Pages\ListSalesOpportunities;
use App\Filament\Resources\SalesOpportunities\Pages\ViewSalesOpportunity;
use App\Models\AiKeywordRule;
use App\Models\Quotation;
use App\Models\SalesOpportunity;
use App\Models\User;
use App\Models\VoiceNoteTranscription;
use App\Services\Employees\KeywordDetectionService;
use App\Services\Employees\OpportunityReviewService;
use App\Services\Sales\QuotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('preserves reviewed opportunity evidence and quotation provenance when the transcription is deleted', function (): void {
    $rule = AiKeywordRule::factory()->create([
        'keyword' => 'scanner',
        'is_active' => true,
    ]);
    $transcription = VoiceNoteTranscription::factory()->transcribed()->create([
        'transcript' => 'The clinic asked for pricing on a new intraoral scanner.',
    ]);

    $opportunity = app(KeywordDetectionService::class)
        ->detect($transcription)
        ->sole();

    $reviewer = User::factory()->admin()->create();
    $this->actingAs($reviewer);

    $opportunity = app(OpportunityReviewService::class)->approve(
        $opportunity,
        'Confirmed during the visit review.',
    );

    $quotation = app(QuotationService::class)->createFromOpportunity($opportunity);

    expect($opportunity->origin_summary)
        ->toBe('The clinic asked for pricing on a new intraoral scanner.')
        ->and($opportunity->status)->toBe(SalesOpportunityStatus::Approved)
        ->and($quotation->sales_opportunity_id)->toBe($opportunity->getKey());

    $reviewedBy = $opportunity->reviewed_by;
    $reviewedAt = $opportunity->reviewed_at?->toDateTimeString();
    $reviewNotes = $opportunity->review_notes;

    $transcription->delete();

    $preserved = $opportunity->fresh();

    expect($preserved)->toBeInstanceOf(SalesOpportunity::class)
        ->and($preserved?->voice_note_transcription_id)->toBeNull()
        ->and($preserved?->reviewed_by)->toBe($reviewedBy)
        ->and($preserved?->reviewed_at?->toDateTimeString())->toBe($reviewedAt)
        ->and($preserved?->review_notes)->toBe($reviewNotes)
        ->and($preserved?->origin_summary)->toBe('The clinic asked for pricing on a new intraoral scanner.')
        ->and($preserved?->isAiOriginated())->toBeTrue()
        ->and($quotation->fresh()?->sales_opportunity_id)->toBe($opportunity->getKey())
        ->and($quotation->fresh()?->salesOpportunity)->toBeInstanceOf(SalesOpportunity::class);

    Livewire::actingAs($reviewer)
        ->test(ViewSalesOpportunity::class, ['record' => $opportunity->getKey()])
        ->assertSuccessful()
        ->assertSee('The clinic asked for pricing on a new intraoral scanner.')
        ->assertSee('Source transcript is no longer retained; this is the preserved origin snapshot.');

    Livewire::actingAs($reviewer)
        ->test(ListSalesOpportunities::class)
        ->assertSuccessful()
        ->assertSee('AI originated');

    $rule->delete();

    expect($preserved?->fresh()?->ai_keyword_rule_id)->toBeNull()
        ->and($preserved?->fresh()?->isAiOriginated())->toBeTrue();
});

it('still requires a transcription when an opportunity is first created', function (): void {
    expect(fn () => SalesOpportunity::query()->create([
        'voice_note_transcription_id' => null,
        'ai_keyword_rule_id' => null,
        'summary' => 'Manual origin must not be introduced by WP-1.10.',
        'origin_summary' => null,
        'status' => SalesOpportunityStatus::Draft,
    ]))->toThrow(
        \DomainException::class,
        'A sales opportunity must originate from a voice note transcription.',
    );
});

it('keeps AI-origin identity when the keyword rule is deleted before the transcript', function (): void {
    $rule = AiKeywordRule::factory()->create([
        'keyword' => 'generator',
        'is_active' => true,
    ]);
    $transcription = VoiceNoteTranscription::factory()->transcribed()->create([
        'transcript' => 'Customer requested a generator replacement quote.',
    ]);

    $opportunity = app(KeywordDetectionService::class)
        ->detect($transcription)
        ->sole();

    expect($opportunity->ai_keyword_rule_id)->toBe($rule->getKey())
        ->and($opportunity->isAiOriginated())->toBeTrue();

    $rule->delete();

    expect($opportunity->fresh()?->ai_keyword_rule_id)->toBeNull()
        ->and($opportunity->fresh()?->origin_summary)->toBe('Customer requested a generator replacement quote.')
        ->and($opportunity->fresh()?->isAiOriginated())->toBeTrue();
});
