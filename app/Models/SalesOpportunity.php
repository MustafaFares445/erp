<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OpportunityCloseReason;
use App\Enums\OpportunityOrigin;
use App\Enums\OpportunityStage;
use App\Enums\SalesOpportunityStatus;
use Database\Factories\SalesOpportunityFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'voice_note_transcription_id', 'ai_keyword_rule_id', 'summary', 'origin_summary', 'status',
    'reviewed_by', 'reviewed_at', 'review_notes', 'origin', 'customer_id', 'lead_id', 'title',
    'estimated_value_minor', 'currency', 'expected_close_date', 'stage', 'probability_percent',
    'owner_id', 'closed_at', 'close_reason', 'close_note',
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
            'origin' => OpportunityOrigin::class,
            'stage' => OpportunityStage::class,
            'close_reason' => OpportunityCloseReason::class,
            'estimated_value_minor' => 'integer',
            'probability_percent' => 'integer',
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
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class, 'customer_id');
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

    /** @return HasMany<OpportunityStageTransition, $this> */
    public function stageTransitions(): HasMany
    {
        return $this->hasMany(OpportunityStageTransition::class)->latest('occurred_at');
    }

    public function resolvedCustomer(): ?CustomerProfile
    {
        if ($this->customer instanceof CustomerProfile) {
            return $this->customer;
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

    /**
     * Derived from retained origin evidence — the AI keyword rule reference
     * or the transcript snapshot taken at creation (WP-1.10) — rather than
     * from the `origin` column alone, which is not guaranteed to be
     * populated on the in-memory model until it is refreshed from the
     * database default, and which a historical row predating this evidence
     * may never have set meaningfully.
     */
    public function isAiOriginated(): bool
    {
        return $this->voice_note_transcription_id !== null
            || $this->ai_keyword_rule_id !== null
            || (is_string($this->origin_summary) && mb_trim($this->origin_summary) !== '');
    }

    public function isHistoricalWithoutCommercialParty(): bool
    {
        return $this->customer_id === null && $this->lead_id === null;
    }

    public function isQuotable(): bool
    {
        return $this->status === SalesOpportunityStatus::Approved && ! $this->stage->isClosed();
    }

    #[\Override]
    protected static function booted(): void
    {
        self::creating(static function (self $opportunity): void {
            // WP-1.10: an AI-originated opportunity still requires its
            // transcription at creation time — only WP-2.2's explicit
            // non-AI origins (Manual, Lead, ExistingCustomer, ...), set by
            // OpportunityService::create(), are exempt. `origin` left unset
            // defaults to `ai_voice_note` at the database level, so a null
            // in-memory value is treated the same as the AI origin here.
            $origin = $opportunity->origin;
            $isAiOrigin = $origin === null || $origin === OpportunityOrigin::AiVoiceNote;

            if ($isAiOrigin && $opportunity->voice_note_transcription_id === null) {
                throw new DomainException('A sales opportunity must originate from a voice note transcription.');
            }
        });

        self::updating(static function (self $opportunity): void {
            if ($opportunity->isDirty('origin')) {
                throw new DomainException('Opportunity origin is immutable after creation.');
            }
        });
    }
}
