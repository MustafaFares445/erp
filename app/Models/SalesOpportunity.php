<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OpportunityCloseReason;
use App\Enums\OpportunityOrigin;
use App\Enums\OpportunityStage;
use App\Enums\SalesOpportunityStatus;
use App\Models\Concerns\ValidatesCurrencyCatalog;
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
    'voice_note_transcription_id',
    'ai_keyword_rule_id',
    'source_visit_id',
    'source_voice_note_id',
    'detected_product_id',
    'detected_product_variant_id',
    'transcript_excerpt',
    'detection_metadata',
    'rejection_reason',
    'summary',
    'origin_summary',
    'status',
    'reviewed_by',
    'reviewed_at',
    'review_notes',
    'origin',
    'customer_id',
    'lead_id',
    'campaign_id',
    'title',
    'estimated_value_minor',
    'currency',
    'expected_close_date',
    'stage',
    'probability_percent',
    'owner_id',
    'closed_at',
    'close_reason',
    'close_note',
])]
final class SalesOpportunity extends Model
{
    /** @use HasFactory<SalesOpportunityFactory> */
    use HasFactory;

    use ValidatesCurrencyCatalog;

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
            'detection_metadata' => 'array',
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

    /** @return BelongsTo<CustomerVisit, $this> */
    public function sourceVisit(): BelongsTo
    {
        return $this->belongsTo(CustomerVisit::class, 'source_visit_id');
    }

    /** @return BelongsTo<EmployeeVoiceNote, $this> */
    public function sourceVoiceNote(): BelongsTo
    {
        return $this->belongsTo(EmployeeVoiceNote::class, 'source_voice_note_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function detectedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'detected_product_id');
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function detectedProductVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'detected_product_variant_id');
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

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
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

        if ($this->lead?->convertedCustomer instanceof CustomerProfile) {
            return $this->lead->convertedCustomer;
        }

        $sourceVisit = $this->source_visit_id !== null ? $this->sourceVisit()->first() : null;

        if ($sourceVisit instanceof CustomerVisit && $sourceVisit->customer instanceof CustomerProfile) {
            return $sourceVisit->customer;
        }

        return $this->transcription?->employeeVoiceNote?->customerVisit?->customer;
    }

    public function resolvedEmployee(): ?EmployeeProfile
    {
        if ($this->owner?->employeeProfile instanceof EmployeeProfile) {
            return $this->owner->employeeProfile;
        }

        $sourceVisit = $this->source_visit_id !== null ? $this->sourceVisit()->first() : null;

        if ($sourceVisit instanceof CustomerVisit && $sourceVisit->employee instanceof EmployeeProfile) {
            return $sourceVisit->employee;
        }

        return $this->transcription?->employeeVoiceNote?->employee;
    }

    public function isAiOriginated(): bool
    {
        return $this->voice_note_transcription_id !== null
            || $this->source_voice_note_id !== null
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
        self::saving(static fn (self $record) => $record->validateActiveCurrency('currency'));

        self::creating(static function (self $opportunity): void {
            $origin = $opportunity->getAttributes()['origin'] ?? null;
            $isAiOrigin = $origin === null || $origin === OpportunityOrigin::AiVoiceNote->value;

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
