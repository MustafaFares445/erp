<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SupportEntitlementStatus;
use App\Models\Concerns\TracksBlameable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'customer_id', 'support_service_level_id', 'serialized_inventory_unit_id',
    'starts_on', 'ends_on', 'status', 'external_reference', 'notes',
])]
final class SupportEntitlement extends Model
{
    use TracksBlameable;

    #[\Override]
    public function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'status' => SupportEntitlementStatus::class,
        ];
    }

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /** @return BelongsTo<SupportServiceLevel, $this> */
    public function serviceLevel(): BelongsTo
    {
        return $this->belongsTo(SupportServiceLevel::class, 'support_service_level_id');
    }

    /** @return BelongsTo<SerializedInventoryUnit, $this> */
    public function serializedInventoryUnit(): BelongsTo
    {
        return $this->belongsTo(SerializedInventoryUnit::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function activeOn(Builder $query, string $date): Builder
    {
        return $query
            ->where('status', SupportEntitlementStatus::Active->value)
            ->where('starts_on', '<=', $date)
            ->where(static fn (Builder $query): Builder => $query
                ->whereNull('ends_on')
                ->orWhere('ends_on', '>=', $date));
    }
}
