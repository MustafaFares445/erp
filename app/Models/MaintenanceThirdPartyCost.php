<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MaintenanceThirdPartyCostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An outside cost incurred on a {@see MaintenanceRecord} job — a
 * subcontracted repair, a courier fee — that is neither a part nor labour
 * (WP-2.9, GAP-MW-09). `bill_id` links it to the payable that actually paid
 * it when one exists; recording the cost never requires one.
 */
#[Fillable([
    'maintenance_record_id',
    'supplier_id',
    'bill_id',
    'description',
    'amount_minor',
    'incurred_on',
    'created_by',
])]
final class MaintenanceThirdPartyCost extends Model
{
    /** @use HasFactory<MaintenanceThirdPartyCostFactory> */
    use HasFactory;

    public const ?string UPDATED_AT = null;

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'incurred_on' => 'date',
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

    /** @return BelongsTo<Bill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
