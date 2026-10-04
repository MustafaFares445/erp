<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EquipmentLoanStatus;
use App\Enums\StockCondition;
use App\Models\Concerns\TracksBlameable;
use App\Services\Inventory\InventoryEquipmentLoanService;
use Database\Factories\EquipmentLoanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A temporary replacement (loaner) unit lent to a customer while their own
 * unit is being repaired. Support records the loan; Inventory owns custody and
 * moves the loaner through {@see InventoryEquipmentLoanService}.
 *
 * @property int $id
 * @property int $maintenance_record_id
 * @property int $customer_id
 * @property int $original_serialized_inventory_unit_id
 * @property int $loaner_serialized_inventory_unit_id
 */
#[Fillable([
    'maintenance_record_id',
    'customer_id',
    'original_serialized_inventory_unit_id',
    'loaner_serialized_inventory_unit_id',
    'status',
    'reserved_at',
    'issued_at',
    'expected_return_at',
    'returned_at',
    'overdue_notified_at',
    'issue_inventory_operation_id',
    'return_inventory_operation_id',
    'issue_inventory_movement_id',
    'return_inventory_movement_id',
    'condition_out',
    'condition_in',
    'notes',
])]
final class EquipmentLoan extends Model
{
    /** @use HasFactory<EquipmentLoanFactory> */
    use HasFactory;

    use TracksBlameable;

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'status' => EquipmentLoanStatus::class,
            'reserved_at' => 'datetime',
            'issued_at' => 'datetime',
            'expected_return_at' => 'datetime',
            'returned_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
            'condition_out' => StockCondition::class,
            'condition_in' => StockCondition::class,
        ];
    }

    public function isOverdue(): bool
    {
        return $this->status === EquipmentLoanStatus::Issued
            && $this->expected_return_at !== null
            && $this->expected_return_at->isPast();
    }

    /** @return BelongsTo<MaintenanceRecord, $this> */
    public function maintenanceRecord(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRecord::class);
    }

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /** @return BelongsTo<SerializedInventoryUnit, $this> */
    public function originalUnit(): BelongsTo
    {
        return $this->belongsTo(SerializedInventoryUnit::class, 'original_serialized_inventory_unit_id');
    }

    /** @return BelongsTo<SerializedInventoryUnit, $this> */
    public function loanerUnit(): BelongsTo
    {
        return $this->belongsTo(SerializedInventoryUnit::class, 'loaner_serialized_inventory_unit_id');
    }
}
