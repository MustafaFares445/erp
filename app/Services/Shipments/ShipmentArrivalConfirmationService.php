<?php

declare(strict_types=1);

namespace App\Services\Shipments;

use App\Enums\ShipmentConfirmationSource;
use App\Models\CustomerProfile;
use App\Models\Shipment;
use App\Models\ShipmentArrivalConfirmation;
use App\Models\User;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Wraps the existing {@see ShipmentService} arrival-confirmation methods
 * (which already own the status transition, locking, and one-time warranty
 * activation) with append-only delivery evidence.
 *
 * A customer confirmation requires at least one photo; admin/system
 * confirmations do not. A shipment already carrying evidence is left
 * untouched on a repeat call — evidence is never overwritten, and this is
 * what makes the whole operation idempotent (the underlying
 * {@see ShipmentService} transition is already idempotent on its own).
 */
final readonly class ShipmentArrivalConfirmationService
{
    public function __construct(private ShipmentService $shipmentService) {}

    /**
     * @param  list<UploadedFile>  $photos
     */
    public function confirmByCustomer(
        Shipment $shipment,
        CustomerProfile $customer,
        array $photos,
        ?string $note = null,
        string $sourceChannel = 'dashboard',
    ): Shipment {
        if ($photos === []) {
            throw new DomainException('Customer arrival confirmation requires at least one delivery photo.');
        }

        $order = $shipment->order;

        if ($order === null || $order->customer_id !== $customer->getKey()) {
            throw new DomainException('This shipment does not belong to the confirming customer.');
        }

        return $this->confirm(
            $shipment,
            fn (Shipment $locked): Shipment => $this->shipmentService->confirmByCustomer($locked, $customer),
            ShipmentConfirmationSource::Customer,
            (int) $customer->getKey(),
            $photos,
            $note,
            $sourceChannel,
        );
    }

    /**
     * @param  list<UploadedFile>  $photos
     */
    public function confirmByAdmin(
        Shipment $shipment,
        User $actor,
        array $photos = [],
        ?string $note = null,
    ): Shipment {
        $actorId = $actor->getKey();

        return $this->confirm(
            $shipment,
            fn (Shipment $locked): Shipment => $this->shipmentService->confirmByAdmin($locked, $actor),
            ShipmentConfirmationSource::AdminUser,
            is_numeric($actorId) ? (int) $actorId : null,
            $photos,
            $note,
            'dashboard',
        );
    }

    public function confirmBySystem(Shipment $shipment): Shipment
    {
        return $this->confirm(
            $shipment,
            fn (Shipment $locked): Shipment => $this->shipmentService->confirmBySystem($locked),
            ShipmentConfirmationSource::System,
            null,
            [],
            null,
            'system',
        );
    }

    /**
     * @param  callable(Shipment): Shipment  $transition
     * @param  list<UploadedFile>  $photos
     */
    private function confirm(
        Shipment $shipment,
        callable $transition,
        ShipmentConfirmationSource $source,
        ?int $actorId,
        array $photos,
        ?string $note,
        string $sourceChannel,
    ): Shipment {
        return DB::transaction(function () use ($shipment, $transition, $source, $actorId, $photos, $note, $sourceChannel): Shipment {
            $updated = $transition($shipment);

            $existing = ShipmentArrivalConfirmation::query()
                ->where('shipment_id', $updated->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing instanceof ShipmentArrivalConfirmation) {
                return $updated;
            }

            $confirmation = ShipmentArrivalConfirmation::query()->create([
                'shipment_id' => $updated->getKey(),
                'confirmed_by_type' => $source,
                'confirmed_by_id' => $actorId,
                'confirmed_at' => now(),
                'source_channel' => $sourceChannel,
                'note' => $note,
            ]);

            foreach ($photos as $photo) {
                $confirmation->addMedia($photo)->toMediaCollection('delivery-confirmation-photos', 'local');
            }

            return $updated;
        }, attempts: 5);
    }
}
