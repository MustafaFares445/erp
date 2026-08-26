<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SalesOpportunityStatus;
use Database\Factories\SalesOpportunityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'voice_note_transcription_id',
    'ai_keyword_rule_id',
    'summary',
    'status',
    'reviewed_by',
    'reviewed_at',
    'review_notes',
])]
final class SalesOpportunity extends Model
{
    /** @use HasFactory<SalesOpportunityFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'status' => SalesOpportunityStatus::class,
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

    /**
     * The quotation this opportunity resulted in, if any (spec 019, FR-025).
     * The link lives on `quotations.sales_opportunity_id` rather than here,
     * so an opportunity gains no new column of its own for it.
     *
     * @return HasOne<Quotation, $this>
     */
    public function quotation(): HasOne
    {
        return $this->hasOne(Quotation::class);
    }

    /**
     * The customer this opportunity is about, resolved through
     * `transcription -> employeeVoiceNote -> customerVisit -> customer`
     * (spec 019, FR-025) — an opportunity carries no `customer_id` of its
     * own, since it originates from a voice note recorded during a visit.
     */
    public function resolvedCustomer(): ?CustomerProfile
    {
        return $this->transcription?->employeeVoiceNote?->customerVisit?->customer;
    }

    public function resolvedEmployee(): ?EmployeeProfile
    {
        return $this->transcription?->employeeVoiceNote?->employee;
    }
}
