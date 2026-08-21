<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VoiceNoteStatus;
use App\Models\Concerns\TracksBlameable;
use App\Observers\EmployeeVoiceNoteObserver;
use Database\Factories\EmployeeVoiceNoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[Fillable(['customer_visit_id', 'employee_id', 'language', 'duration_seconds', 'status'])]
#[ObservedBy(EmployeeVoiceNoteObserver::class)]
final class EmployeeVoiceNote extends Model implements HasMedia
{
    /** @use HasFactory<EmployeeVoiceNoteFactory> */
    use HasFactory;

    use InteractsWithMedia;
    use SoftDeletes;
    use TracksBlameable;

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'status' => VoiceNoteStatus::class,
        ];
    }

    /**
     * Single audio recording, replacing the ERD's `audio_path` column
     * (D1/R-005). Private disk; served only through a temporary signed URL
     * (FR-083).
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('voice-note-audio')->useDisk('local')->singleFile();
    }

    /**
     * @return BelongsTo<CustomerVisit, $this>
     */
    public function customerVisit(): BelongsTo
    {
        return $this->belongsTo(CustomerVisit::class);
    }

    /**
     * @return BelongsTo<EmployeeProfile, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class);
    }

    /**
     * @return HasOne<VoiceNoteTranscription, $this>
     */
    public function transcription(): HasOne
    {
        return $this->hasOne(VoiceNoteTranscription::class);
    }
}
