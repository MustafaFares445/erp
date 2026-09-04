<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SalesOpportunityReviewStatus;
use App\Enums\SalesOpportunityStatus;
use Database\Factories\SalesOpportunityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'voice_note_transcription_id',
    'ai_keyword_rule_id',
    'customer_profile_id',
    'lead_id',
    'owner_id',
    'summary',
    'origin_summary',
    'estimated_value',
    'expected_close_date',
    'status',
    'review_status',
    'reviewed_by',
    'reviewed_at',
    'review_notes',
    'close_reason',
    'closed_at',
])]
final class SalesOpportunity extends Model
{
    /** @use HasFactory<SalesOpportunityFactory> */
    use HasFactory;

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'status' => SalesOpportunityStatus::class,
            'review_status' => SalesOpportunityReviewStatus::class,
            'estimated_value' => 'decimal:2',
            'expected_close_date' => 'date',
            'reviewed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<VoiceNoteTranscription, $this> */
    public function transcription(): BelongsTo
    {
        return $this->belongsTo(VoiceNoteTranscription::class, 'voice_note_transcription_id');
    }

    /** @return BelongsTo<AiKeywordRule, $this> */
    public function keywordRule(): BelongsTo
    {
        return $this->belongsTo(AiKeywordRule::class, 'ai_keyword_rule_id');
    }

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return HasOne<Quotation, $this> */
    public function quotation(): HasOne
    {
        return $this->hasOne(Quotation::class);
    }

    /** @return MorphMany<Interaction, $this> */
    public function interactions(): MorphMany
    {
        return $this->morphMany(Interaction::class, 'subject')->latest('occurred_at');
    }

    public function resolvedCustomer(): ?CustomerProfile
    {
        if ($this->customerProfile instanceof CustomerProfile) {
            return $this->customerProfile;
        }

        return $this->transcription?->employeeVoiceNote?->customerVisit?->customer;
    }

    public function resolvedEmployee(): ?EmployeeProfile
    {
        if ($this->owner?->employeeProfile instanceof EmployeeProfile) {
            return $this->owner->employeeProfile;
        }

        return $this->transcription?->employeeVoiceNote?->employee;
    }

    public function isAiOriginated(): bool
    {
        return $this->voice_note_transcription_id !== null
            || $this->ai_keyword_rule_id !== null
            || (is_string($this->origin_summary) && mb_trim($this->origin_summary) !== '');
    }

    public function isQuotable(): bool
    {
        return in_array($this->review_status, [SalesOpportunityReviewStatus::Approved, SalesOpportunityReviewStatus::NotRequired], true)
            && in_array($this->status, [SalesOpportunityStatus::Draft, SalesOpportunityStatus::Qualified], true);
    }
}
