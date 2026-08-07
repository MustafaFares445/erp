<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Enums\OpportunityDraftStatus;
use App\Models\AiKeywordRule;
use App\Models\SalesOpportunityDraft;
use App\Models\VoiceNoteTranscription;
use Illuminate\Support\Collection;

/**
 * Matches active {@see AiKeywordRule}s against a transcript and creates a
 * `Draft` {@see SalesOpportunityDraft} per match (FR-052, FR-053). Every
 * draft starts `Draft`; none is ever approved automatically (FR-054).
 */
final class KeywordDetectionService
{
    /**
     * @return Collection<int, SalesOpportunityDraft>
     */
    public function detect(VoiceNoteTranscription $transcription): Collection
    {
        /** @var Collection<int, SalesOpportunityDraft> $drafts */
        $drafts = collect();

        $transcript = $transcription->transcript;

        if ($transcript === null || mb_trim($transcript) === '') {
            return $drafts;
        }

        $haystack = mb_strtolower($transcript);

        foreach (AiKeywordRule::query()->where('is_active', true)->get() as $rule) {
            if (! str_contains($haystack, mb_strtolower($rule->keyword))) {
                continue;
            }

            $drafts->push(SalesOpportunityDraft::query()->create([
                'voice_note_transcription_id' => $transcription->id,
                'ai_keyword_rule_id' => $rule->id,
                'summary' => sprintf('Possible interest in "%s" detected in the visit transcript.', $rule->keyword),
                'status' => OpportunityDraftStatus::Draft,
            ]));
        }

        return $drafts;
    }
}
