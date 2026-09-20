<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ShipmentConfirmationSource;
use App\Services\Shipments\ShipmentArrivalConfirmationService;
use Database\Factories\ShipmentArrivalConfirmationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Append-only delivery evidence — created only by
 * {@see ShipmentArrivalConfirmationService}, never
 * updated. `confirmedByCustomer()`/`confirmedByUser()` are only meaningful
 * for the matching `confirmed_by_type`, mirroring `Shipment` itself.
 */
#[Fillable(['shipment_id', 'confirmed_by_type', 'confirmed_by_id', 'confirmed_at', 'source_channel', 'note'])]
final class ShipmentArrivalConfirmation extends Model implements HasMedia
{
    /** @use HasFactory<ShipmentArrivalConfirmationFactory> */
    use HasFactory;

    use InteractsWithMedia;

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'confirmed_by_type' => ShipmentConfirmationSource::class,
            'confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Shipment, $this> */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /** @return BelongsTo<CustomerProfile, $this> */
    public function confirmedByCustomer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class, 'confirmed_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('delivery-confirmation-photos')->useDisk('local');
    }
}
