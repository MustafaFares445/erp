<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\VisitGpsLogFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only GPS trail point for a {@see CustomerVisit} (data-model.md §6).
 * No `created_at`/`updated_at`, no soft delete, no update path.
 */
#[Fillable(['customer_visit_id', 'latitude', 'longitude', 'recorded_at'])]
final class VisitGpsLog extends Model
{
    /** @use HasFactory<VisitGpsLogFactory> */
    use HasFactory;

    public $timestamps = false;

    #[\Override]
    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new DomainException('GPS log entries are append-only and cannot be updated.');
        });

        self::deleting(function (): never {
            throw new DomainException('GPS log entries are append-only and cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CustomerVisit, $this>
     */
    public function customerVisit(): BelongsTo
    {
        return $this->belongsTo(CustomerVisit::class);
    }
}
