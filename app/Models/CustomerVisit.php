<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SerializedCustodyType;
use App\Enums\VisitOutcome;
use App\Enums\VisitStatus;
use App\Models\Concerns\Favoritable;
use App\Models\Concerns\HasFavorites;
use App\Models\Concerns\TracksBlameable;
use Carbon\Carbon;
use Database\Factories\CustomerVisitFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[Fillable([
    'reference',
    'employee_id',
    'plan_task_id',
    'customer_id',
    'serialized_inventory_unit_id',
    'visit_type',
    'planned_at',
    'scheduled_start_at',
    'scheduled_end_at',
    'en_route_at',
    'checked_in_at',
    'checked_out_at',
    'check_in_latitude',
    'check_in_longitude',
    'check_in_recorded_at',
    'check_in_accuracy_meters',
    'distance_from_customer_meters',
    'location_warning',
    'location_override_reason',
    'location_overridden_by',
    'location_overridden_at',
    'schedule_override_reason',
    'schedule_overridden_by',
    'schedule_overridden_at',
    'outcome',
    'outcome_code',
    'outcome_notes',
    'employee_notes',
    'follow_up_required',
    'follow_up_date',
    'follow_up_note',
    'review_note',
    'reviewed_by',
    'reviewed_at',
    'status',
])]
final class CustomerVisit extends Model implements Favoritable, HasMedia
{
    /** @use HasFactory<CustomerVisitFactory> */
    use HasFactory;

    use HasFavorites;
    use InteractsWithMedia;
    use SoftDeletes;
    use TracksBlameable;

    #[\Override]
    protected static function booted(): void
    {
        self::saving(static function (self $visit): void {
            if ($visit->checked_in_at !== null && $visit->checked_out_at !== null && $visit->checked_out_at->lessThan($visit->checked_in_at)) {
                throw new DomainException('Check-out cannot occur before check-in.');
            }

            if ($visit->status === VisitStatus::Completed && $visit->outcome_code === null) {
                throw new DomainException('A completed visit requires a valid visit outcome.');
            }

            if ($visit->follow_up_required && $visit->follow_up_date === null) {
                throw new DomainException('A follow-up date is required when follow-up is requested.');
            }

            if ($visit->schedule_overridden_at !== null && mb_trim((string) $visit->schedule_override_reason) === '') {
                throw new DomainException('A schedule conflict override reason is required.');
            }

            if ($visit->serialized_inventory_unit_id !== null) {
                $validEquipment = SerializedInventoryUnit::query()
                    ->whereKey($visit->serialized_inventory_unit_id)
                    ->where('custody_type', SerializedCustodyType::Customer->value)
                    ->where('custody_reference_type', 'customer')
                    ->where('custody_reference_id', $visit->customer_id)
                    ->exists();

                if (! $validEquipment) {
                    throw new DomainException('Selected equipment must be owned by the visit customer.');
                }
            }
        });
    }

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'planned_at' => 'datetime',
            'scheduled_start_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
            'en_route_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'check_in_recorded_at' => 'datetime',
            'check_in_latitude' => 'decimal:7',
            'check_in_longitude' => 'decimal:7',
            'check_in_accuracy_meters' => 'decimal:2',
            'distance_from_customer_meters' => 'integer',
            'location_warning' => 'boolean',
            'location_overridden_at' => 'datetime',
            'schedule_overridden_at' => 'datetime',
            'outcome_code' => VisitOutcome::class,
            'follow_up_required' => 'boolean',
            'follow_up_date' => 'date',
            'reviewed_at' => 'datetime',
            'status' => VisitStatus::class,
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('visit-attachments')->useDisk('local');
    }

    /** @return BelongsTo<EmployeeProfile, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class);
    }

    /** @return BelongsTo<PlanTask, $this> */
    public function planTask(): BelongsTo
    {
        return $this->belongsTo(PlanTask::class);
    }

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /** @return BelongsTo<SerializedInventoryUnit, $this> */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(SerializedInventoryUnit::class, 'serialized_inventory_unit_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function scheduleOverriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'schedule_overridden_by');
    }

    /** @return BelongsTo<User, $this> */
    public function locationOverriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'location_overridden_by');
    }

    /** @return HasMany<VisitGpsLog, $this> */
    public function gpsLogs(): HasMany
    {
        return $this->hasMany(VisitGpsLog::class)->orderBy('recorded_at');
    }

    /** @return HasMany<EmployeeVoiceNote, $this> */
    public function voiceNotes(): HasMany
    {
        return $this->hasMany(EmployeeVoiceNote::class)->latest();
    }

    /** @return HasOne<PlanTask, $this> */
    public function followUpTask(): HasOne
    {
        return $this->hasOne(PlanTask::class, 'source_visit_id');
    }

    /**
     * @return Collection<int, SalesOpportunity>
     */
    public function salesOpportunities(): Collection
    {
        return $this->voiceNotes
            ->map(static fn (EmployeeVoiceNote $note): ?VoiceNoteTranscription => $note->transcription)
            ->filter()
            ->flatMap(static fn (VoiceNoteTranscription $transcription): Collection => $transcription->salesOpportunities)
            ->values();
    }

    public function durationMinutes(): ?int
    {
        if ($this->checked_in_at === null || $this->checked_out_at === null) {
            return null;
        }

        return (int) $this->checked_in_at->diffInMinutes($this->checked_out_at);
    }

    public function effectiveScheduledStart(): ?Carbon
    {
        return $this->scheduled_start_at ?? $this->planned_at;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            VisitStatus::Completed,
            VisitStatus::UnableToComplete,
            VisitStatus::Cancelled,
        ], true);
    }
}
