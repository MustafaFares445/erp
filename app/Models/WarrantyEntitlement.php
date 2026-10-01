<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WarrantyDurationUnit;
use App\Enums\WarrantyEntitlementState;
use App\Enums\WarrantyStartTrigger;
use Carbon\CarbonInterface;
use Database\Factories\WarrantyEntitlementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'serialized_inventory_unit_id',
    'customer_id',
    'warranty_policy_id',
    'source_shipment_id',
    'replacement_of_entitlement_id',
    'state',
    'policy_name',
    'duration_value',
    'duration_unit',
    'start_trigger',
    'covers_parts',
    'covers_labour',
    'covers_travel',
    'covers_consumables',
    'covers_third_party',
    'transferable',
    'replacement_rule',
    'starts_on',
    'expires_on',
    'ended_at',
    'end_reason',
])]
final class WarrantyEntitlement extends Model
{
    /** @use HasFactory<WarrantyEntitlementFactory> */
    use HasFactory;

    #[\Override]
    protected function casts(): array
    {
        return [
            'state' => WarrantyEntitlementState::class,
            'duration_value' => 'integer',
            'duration_unit' => WarrantyDurationUnit::class,
            'start_trigger' => WarrantyStartTrigger::class,
            'covers_parts' => 'boolean',
            'covers_labour' => 'boolean',
            'covers_travel' => 'boolean',
            'covers_consumables' => 'boolean',
            'covers_third_party' => 'boolean',
            'transferable' => 'boolean',
            'starts_on' => 'date',
            'expires_on' => 'date',
            'ended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SerializedInventoryUnit, $this> */
    public function serializedInventoryUnit(): BelongsTo
    {
        return $this->belongsTo(SerializedInventoryUnit::class);
    }

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /** @return BelongsTo<WarrantyPolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(WarrantyPolicy::class, 'warranty_policy_id');
    }

    /** @return BelongsTo<Shipment, $this> */
    public function sourceShipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'source_shipment_id');
    }

    /** @return BelongsTo<WarrantyEntitlement, $this> */
    public function replacementOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replacement_of_entitlement_id');
    }

    public function isActiveAt(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $this->state === WarrantyEntitlementState::Active
            && $this->starts_on !== null
            && $this->expires_on !== null
            && $at->copy()->startOfDay()->betweenIncluded(
                $this->starts_on->copy()->startOfDay(),
                $this->expires_on->copy()->endOfDay(),
            );
    }
}
