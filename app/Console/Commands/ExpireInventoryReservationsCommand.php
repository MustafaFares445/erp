<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ReservationStatus;
use App\Models\InventoryReservation;
use App\Services\Inventory\InventoryReservationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

#[Signature('inventory:reservations:expire')]
#[Description('Expire active reservations whose validity has lapsed, freeing the stock they hold.')]
final class ExpireInventoryReservationsCommand extends Command
{
    public function handle(InventoryReservationService $reservations): int
    {
        $expired = 0;
        $failed = 0;

        InventoryReservation::query()
            ->where('status', ReservationStatus::Active->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(500, function (Collection $batch) use ($reservations, &$expired, &$failed): void {
                foreach ($batch as $reservation) {
                    try {
                        $reservations->expire($reservation);

                        if ($reservation->refresh()->status === ReservationStatus::Expired) {
                            $expired++;
                        }
                    } catch (Throwable $exception) {
                        $failed++;

                        $this->components->error(sprintf(
                            'Reservation #%d failed to expire: %s',
                            $reservation->getKey(),
                            $exception->getMessage(),
                        ));
                    }
                }
            });

        $this->components->info(sprintf(
            'Reservation expiry sweep completed: %d expired, %d failed.',
            $expired,
            $failed,
        ));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
