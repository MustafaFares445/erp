<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SalesOpportunityReviewStatus;
use App\Enums\SalesOpportunityStatus;
use App\Models\SalesOpportunity;
use App\Models\VoiceNoteTranscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SalesOpportunity> */
final class SalesOpportunityFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'voice_note_transcription_id' => VoiceNoteTranscription::factory(),
            'ai_keyword_rule_id' => null,
            'customer_profile_id' => null,
            'lead_id' => null,
            'owner_id' => null,
            'summary' => fake()->sentence(10),
            'origin_summary' => null,
            'estimated_value' => null,
            'expected_close_date' => null,
            'status' => SalesOpportunityStatus::Draft,
            'review_status' => SalesOpportunityReviewStatus::Pending,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_notes' => null,
            'close_reason' => null,
            'closed_at' => null,
        ];
    }

    public function manual(): self
    {
        return $this->state(fn (): array => [
            'voice_note_transcription_id' => null,
            'review_status' => SalesOpportunityReviewStatus::NotRequired,
        ]);
    }
}
