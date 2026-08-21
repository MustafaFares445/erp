<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ShipmentConfirmationSource;
use App\Enums\ShipmentStatus;
use Database\Factories\ShipmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[Fillable([
    'order_id', 'inventory_operation_id', 'warehouse_id', 'tracking_number', 'status',
    'confirmed_by_type', 'confirmed_by_id', 'confirmed_at',
])]
final class Shipment extends Model implements HasMedia
{
    /** @use HasFactory<ShipmentFactory> */
    use HasFactory;

    use InteractsWithMedia;

    protected $attributes = [
        'status' => ShipmentStatus::InTransit->value,
    ];

    #[\Override]
    protected static function booted(): void
    {
        self::creating(function (Shipment $shipment): void {
            if (blank($shipment->tracking_number)) {
                $shipment->tracking_number = self::generateTrackingNumber();
            }
        });
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'confirmed_by_type' => ShipmentConfirmationSource::class,
            'confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<InventoryOperation, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(InventoryOperation::class, 'inventory_operation_id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<User, $this> */
    public function confirmedByAdminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_id');
    }

    /** @return BelongsTo<CustomerProfile, $this> */
    public function confirmedByCustomer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class, 'confirmed_by_id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')->useDisk('local');
    }

    public function isInTransit(): bool
    {
        return $this->status === ShipmentStatus::InTransit;
    }

    public function isArrived(): bool
    {
        return $this->status === ShipmentStatus::Arrived;
    }

    public function confirmedByLabel(): ?string
    {
        return match ($this->confirmed_by_type) {
            ShipmentConfirmationSource::AdminUser => $this->confirmedByAdminUserName(),
            ShipmentConfirmationSource::Customer => $this->confirmedByCustomerName(),
            ShipmentConfirmationSource::System => ShipmentConfirmationSource::System->label(),
            default => null,
        };
    }

    public function confirmByAdmin(User $user): void
    {
        $this->forceFill([
            'status' => ShipmentStatus::Arrived,
            'confirmed_by_type' => ShipmentConfirmationSource::AdminUser,
            'confirmed_by_id' => $user->getKey(),
            'confirmed_at' => now(),
        ])->save();
    }

    public function confirmByCustomer(CustomerProfile $customer): void
    {
        $this->forceFill([
            'status' => ShipmentStatus::Arrived,
            'confirmed_by_type' => ShipmentConfirmationSource::Customer,
            'confirmed_by_id' => $customer->getKey(),
            'confirmed_at' => now(),
        ])->save();
    }

    public function confirmBySystem(): void
    {
        $this->forceFill([
            'status' => ShipmentStatus::Arrived,
            'confirmed_by_type' => ShipmentConfirmationSource::System,
            'confirmed_by_id' => null,
            'confirmed_at' => now(),
        ])->save();
    }

    private function confirmedByAdminUserName(): string
    {
        $user = $this->relationLoaded('confirmedByAdminUser')
            ? $this->getRelation('confirmedByAdminUser')
            : $this->confirmedByAdminUser()->first();

        return $user instanceof User ? $user->name : ShipmentConfirmationSource::AdminUser->label();
    }

    private function confirmedByCustomerName(): string
    {
        $customer = $this->relationLoaded('confirmedByCustomer')
            ? $this->getRelation('confirmedByCustomer')
            : $this->confirmedByCustomer()->first();

        return $customer instanceof CustomerProfile && filled($customer->company_name)
            ? $customer->company_name
            : ShipmentConfirmationSource::Customer->label();
    }

    private static function generateTrackingNumber(): string
    {
        do {
            $trackingNumber = 'TRK-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (self::query()->where('tracking_number', $trackingNumber)->exists());

        return $trackingNumber;
    }
}
