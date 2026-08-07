<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OpportunityDraftStatus;
use Database\Factories\SalesOpportunityDraftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'voice_note_transcription_id',
    'ai_keyword_rule_id',
    'summary',
    'status',
    'reviewed_by',
    'reviewed_at',
    'review_notes',
])]
final class SalesOpportunityDraft extends Model
{
    /** @use HasFactory<SalesOpportunityDraftFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'status' => OpportunityDraftStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<VoiceNoteTranscription, $this>
     */
    public function transcription(): BelongsTo
    {
        return $this->belongsTo(VoiceNoteTranscription::class, 'voice_note_transcription_id');
    }

    /**
     * @return BelongsTo<AiKeywordRule, $this>
     */
    public function keywordRule(): BelongsTo
    {
        return $this->belongsTo(AiKeywordRule::class, 'ai_keyword_rule_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
