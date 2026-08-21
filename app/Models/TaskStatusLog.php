<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanTaskStatus;
use Database\Factories\TaskStatusLogFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only history of a {@see PlanTask}'s status changes
 * (data-model.md §4). No `updated_at`, no soft delete, no update path.
 */
#[Fillable(['plan_task_id', 'from_status', 'to_status', 'note', 'actor_id', 'created_at'])]
final class TaskStatusLog extends Model
{
    /** @use HasFactory<TaskStatusLogFactory> */
    use HasFactory;

    public const ?string UPDATED_AT = null;

    #[\Override]
    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new DomainException('Task status log entries are append-only and cannot be updated.');
        });

        self::deleting(function (): never {
            throw new DomainException('Task status log entries are append-only and cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'from_status' => PlanTaskStatus::class,
            'to_status' => PlanTaskStatus::class,
        ];
    }

    /**
     * @return BelongsTo<PlanTask, $this>
     */
    public function planTask(): BelongsTo
    {
        return $this->belongsTo(PlanTask::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
