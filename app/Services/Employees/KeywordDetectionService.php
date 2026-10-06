<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Enums\SalesOpportunityStatus;
use App\Models\AiKeywordRule;
use App\Models\SalesOpportunity;
use App\Models\VoiceNoteTranscription;
use Illuminate\Support\Collection;

final class KeywordDetectionService
{
    /** @return Collection<int, SalesOpportunity> */
    public function detect(VoiceNoteTranscription $transcription): Collection
    {
        /** @var Collection<int, SalesOpportunity> $opportunities */
        $opportunities = collect();
        $transcript = $transcription->transcript;

        if ($transcript === null || mb_trim($transcript) === '') {
            return $opportunities;
        }

        $transcription->loadMissing('employeeVoiceNote.customerVisit');
        $voiceNote = $transcription->employeeVoiceNote;
        $visit = $voiceNote?->customerVisit;
        $haystack = mb_strtolower($transcript);

        foreach (AiKeywordRule::query()->where('is_active', true)->get() as $rule) {
            if (! str_contains($haystack, mb_strtolower($rule->keyword))) {
                continue;
            }

            $opportunities->push(SalesOpportunity::query()->firstOrCreate(
                [
                    'voice_note_transcription_id' => $transcription->id,
                    'ai_keyword_rule_id' => $rule->id,
                ],
                [
                    'source_visit_id' => $visit?->getKey(),
                    'source_voice_note_id' => $voiceNote?->getKey(),
                    'customer_id' => $visit?->customer_id,
                    'detected_product_id' => $rule->product_id,
                    'detected_product_variant_id' => $rule->product_variant_id,
                    'transcript_excerpt' => mb_substr($transcript, 0, 1200),
                    'detection_metadata' => [
                        'keyword' => $rule->keyword,
                        'detected_at' => now()->toIso8601String(),
                    ],
                    'summary' => sprintf('Possible interest in "%s" detected in the visit transcript.', $rule->keyword),
                    'origin_summary' => $transcript,
                    'status' => SalesOpportunityStatus::Draft,
                ],
            ));
        }

        return $opportunities;
    }
}
