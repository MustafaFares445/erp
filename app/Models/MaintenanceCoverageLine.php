<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WarrantyCoverageSource;
use App\Enums\WarrantyLineCategory;
use Database\Factories\MaintenanceCoverageLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'maintenance_record_id',
    'category',
    'description',
    'source_type',
    'source_id',
    'amount_minor',
    'coverage_percent',
    'covered_amount_minor',
    'customer_amount_minor',
    'coverage_source',
    'notes',
    'decided_by',
])]
final class MaintenanceCoverageLine extends Model
{
    /** @use HasFactory<MaintenanceCoverageLineFactory> */
    use HasFactory;

    #[\Override]
    protected function casts(): array
    {
        return [
            'category' => WarrantyLineCategory::class,
            'coverage_source' => WarrantyCoverageSource::class,
            'amount_minor' => 'integer',
            'coverage_percent' => 'decimal:2',
            'covered_amount_minor' => 'integer',
            'customer_amount_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<MaintenanceRecord, $this> */
    public function maintenanceRecord(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRecord::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
