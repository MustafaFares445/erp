<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WarrantyCoverageSource;
use App\Enums\WarrantyRecoveryOutcome;
use App\Enums\WarrantyRecoveryStatus;
use Database\Factories\WarrantyRecoveryClaimFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 */
#[Fillable([
    'maintenance_record_id',
    'coverage_source',
    'supplier_id',
    'counterparty_name',
    'external_reference',
    'status',
    'recovery_outcome',
    'currency',
    'claimed_amount_minor',
    'approved_amount_minor',
    'received_amount_minor',
    'submitted_at',
    'decided_at',
    'received_at',
    'notes',
    'rejection_reason',
    'created_by',
    'updated_by',
])]
final class WarrantyRecoveryClaim extends Model
{
    /** @use HasFactory<WarrantyRecoveryClaimFactory> */
    use HasFactory;

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'coverage_source' => WarrantyCoverageSource::class,
            'status' => WarrantyRecoveryStatus::class,
            'recovery_outcome' => WarrantyRecoveryOutcome::class,
            'claimed_amount_minor' => 'integer',
            'approved_amount_minor' => 'integer',
            'received_amount_minor' => 'integer',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MaintenanceRecord, $this> */
    public function maintenanceRecord(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRecord::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function outstandingMinor(): int
    {
        $approved = $this->approved_amount_minor ?? $this->claimed_amount_minor;

        return max(0, $approved - $this->received_amount_minor);
    }
}
