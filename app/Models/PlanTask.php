<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanTaskStatus;
use App\Models\Concerns\TracksBlameable;
use Database\Factories\PlanTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

#[Fillable(['sales_plan_id', 'customer_id', 'title', 'description', 'starts_at', 'due_at', 'status'])]
final class PlanTask extends Model
{
    /** @use HasFactory<PlanTaskFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'starts_at' => 'date',
            'due_at' => 'date',
            'completed_at' => 'datetime',
            'status' => PlanTaskStatus::class,
        ];
    }

    /**
     * @return BelongsTo<SalesPlan, $this>
     */
    public function salesPlan(): BelongsTo
    {
        return $this->belongsTo(SalesPlan::class);
    }

    /**
     * @return BelongsTo<CustomerProfile, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /**
     * @return HasMany<TaskStatusLog, $this>
     */
    public function statusLogs(): HasMany
    {
        return $this->hasMany(TaskStatusLog::class);
    }

    /**
     * @param  Builder<PlanTask>  $query
     * @return Builder<PlanTask>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNotIn('status', [PlanTaskStatus::Completed, PlanTaskStatus::Cancelled])
            ->whereDate('due_at', '<', Carbon::today());
    }

    /**
     * FR-034 does not specify a "near-due" threshold; this uses 3 days,
     * chosen as a low-risk display default that is easy to revisit later
     * without any data migration.
     *
     * @param  Builder<PlanTask>  $query
     * @return Builder<PlanTask>
     */
    public function scopeDueSoon(Builder $query): Builder
    {
        return $query->whereNotIn('status', [PlanTaskStatus::Completed, PlanTaskStatus::Cancelled])
            ->whereDate('due_at', '>=', Carbon::today())
            ->whereDate('due_at', '<=', Carbon::today()->addDays(3));
    }
}
