<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TranscriptionConfidenceSource;
use App\Enums\TranscriptionStatus;
use Database\Factories\VoiceNoteTranscriptionFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'employee_voice_note_id',
    'transcript',
    'confidence',
    'confidence_source',
    'detected_language',
    'provider',
    'error_message',
    'status',
])]
final class VoiceNoteTranscription extends Model
{
    /** @use HasFactory<VoiceNoteTranscriptionFactory> */
    use HasFactory;

    #[\Override]
    protected static function booted(): void
    {
        self::saving(function (VoiceNoteTranscription $transcription): void {
            $confidence = $transcription->confidence;
            $isUnavailable = $transcription->confidence_source === TranscriptionConfidenceSource::Unavailable;

            if ($confidence !== null && ((float) $confidence < 0.0 || (float) $confidence > 100.0)) {
                throw new DomainException(__('admin.employees.errors.confidence_out_of_range'));
            }

            if ($confidence === null && ! $isUnavailable) {
                throw new DomainException(__('admin.employees.errors.confidence_requires_unavailable_source'));
            }

            if ($confidence !== null && $isUnavailable) {
                throw new DomainException(__('admin.employees.errors.unavailable_source_requires_null_confidence'));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'confidence' => 'decimal:2',
            'confidence_source' => TranscriptionConfidenceSource::class,
            'status' => TranscriptionStatus::class,
        ];
    }

    /**
     * @return BelongsTo<EmployeeVoiceNote, $this>
     */
    public function employeeVoiceNote(): BelongsTo
    {
        return $this->belongsTo(EmployeeVoiceNote::class);
    }

    /**
     * @return HasMany<SalesOpportunity, $this>
     */
    public function salesOpportunities(): HasMany
    {
        return $this->hasMany(SalesOpportunity::class);
    }

    /**
     * The single source of truth for how confidence is displayed (§11.2,
     * D6): a derived value is always marked distinct from a provider-
     * reported one, and a null value never renders as `0.00%`.
     */
    public function confidenceLabel(): string
    {
        return match ($this->confidence_source) {
            TranscriptionConfidenceSource::ProviderReported => number_format((float) $this->confidence, 2).'%',
            TranscriptionConfidenceSource::DerivedFromLogProb => '≈ '.number_format((float) $this->confidence, 2).'%',
            TranscriptionConfidenceSource::Unavailable => __('admin.employees.confidence.unavailable'),
        };
    }
}
