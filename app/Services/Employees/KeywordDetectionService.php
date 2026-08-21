<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Enums\SalesOpportunityStatus;
use App\Models\AiKeywordRule;
use App\Models\SalesOpportunity;
use App\Models\VoiceNoteTranscription;
use Illuminate\Support\Collection;

/**
 * Matches active {@see AiKeywordRule}s against a transcript and creates a
 * `Draft` {@see SalesOpportunity} per match (FR-052, FR-053). Every
 * opportunity starts `Draft`; none is ever approved automatically (FR-054).
 */
final class KeywordDetectionService
{
    /**
     * @return Collection<int, SalesOpportunity>
     */
    public function detect(VoiceNoteTranscription $transcription): Collection
    {
        /** @var Collection<int, SalesOpportunity> $opportunities */
        $opportunities = collect();

        $transcript = $transcription->transcript;

        if ($transcript === null || mb_trim($transcript) === '') {
            return $opportunities;
        }

        $haystack = mb_strtolower($transcript);

        foreach (AiKeywordRule::query()->where('is_active', true)->get() as $rule) {
            if (! str_contains($haystack, mb_strtolower($rule->keyword))) {
                continue;
            }

            $opportunities->push(SalesOpportunity::query()->create([
                'voice_note_transcription_id' => $transcription->id,
                'ai_keyword_rule_id' => $rule->id,
                'summary' => sprintf('Possible interest in "%s" detected in the visit transcript.', $rule->keyword),
                'status' => SalesOpportunityStatus::Draft,
            ]));
        }

        return $opportunities;
    }
}
