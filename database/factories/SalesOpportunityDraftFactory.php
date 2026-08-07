<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OpportunityDraftStatus;
use App\Models\SalesOpportunityDraft;
use App\Models\VoiceNoteTranscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesOpportunityDraft>
 */
final class SalesOpportunityDraftFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'voice_note_transcription_id' => VoiceNoteTranscription::factory(),
            'ai_keyword_rule_id' => null,
            'summary' => fake()->sentence(10),
            'status' => OpportunityDraftStatus::Draft,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_notes' => null,
        ];
    }
}
