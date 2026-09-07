<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\InventoryReservationExpired;
use Illuminate\Database\Eloquent\Model;

final readonly class RecordLapsedReservationOnSourceDocument
{
    public function handle(InventoryReservationExpired $event): void
    {
        $subject = $event->sourceDocument instanceof Model
            ? $event->sourceDocument
            : $event->reservation;

        activity()
            ->performedOn($subject)
            ->withChanges([
                'attributes' => [
                    'reservation_id' => $event->reservation->getKey(),
                    'reservation_status' => $event->reservation->status->value,
                ],
            ])
            ->withProperties([
                'source_channel' => 'scheduler',
                'reservation_id' => $event->reservation->getKey(),
                'product_variant_id' => $event->reservation->product_variant_id,
                'warehouse_id' => $event->reservation->warehouse_id,
                'expired_at' => $event->reservation->released_at?->toDateTimeString(),
            ])
            ->log('inventory.reservation.expired');
    }
}
