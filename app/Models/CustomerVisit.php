<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VisitRecordChannel;
use App\Enums\VisitStatus;
use App\Models\Concerns\TracksBlameable;
use Database\Factories\CustomerVisitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[Fillable([
    'employee_id',
    'plan_task_id',
    'customer_id',
    'recorded_channel',
    'planned_at',
    'checked_in_at',
    'checked_out_at',
    'outcome',
    'review_note',
    'reviewed_by',
    'reviewed_at',
    'status',
])]
final class CustomerVisit extends Model implements HasMedia
{
    /** @use HasFactory<CustomerVisitFactory> */
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
            'recorded_channel' => VisitRecordChannel::class,
            'planned_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'status' => VisitStatus::class,
        ];
    }

    /**
     * Field-recorded images/files attached to the visit (FR-043), replacing
     * the ERD's dropped `visit_attachments` table (D1/R-005). Private disk
     * because these may contain customer premises photos.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('visit-attachments')->useDisk('local');
    }

    /**
     * @return BelongsTo<EmployeeProfile, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class);
    }

    /**
     * @return BelongsTo<PlanTask, $this>
     */
    public function planTask(): BelongsTo
    {
        return $this->belongsTo(PlanTask::class);
    }

    /**
     * @return BelongsTo<CustomerProfile, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return HasMany<VisitGpsLog, $this>
     */
    public function gpsLogs(): HasMany
    {
        return $this->hasMany(VisitGpsLog::class)->orderBy('recorded_at');
    }

    /**
     * Derived duration (FR-041); never stored, so it cannot drift from the
     * two timestamps it is computed from.
     */
    public function durationMinutes(): ?int
    {
        if ($this->checked_in_at === null || $this->checked_out_at === null) {
            return null;
        }

        return (int) $this->checked_in_at->diffInMinutes($this->checked_out_at);
    }
}
