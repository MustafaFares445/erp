<?php

declare(strict_types=1);

namespace App\Services\Shipments;

use App\Enums\OperationStage;
use App\Enums\ShipmentStatus;
use App\Models\CustomerProfile;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Support\WarrantyActivationService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class ShipmentService
{
    public function __construct(private WarrantyActivationService $warrantyActivationService) {}

    /** @return Builder<Shipment> */
    public function eligibleForAutomaticArrival(): Builder
    {
        return Shipment::query()
            ->where('status', ShipmentStatus::InTransit->value)
            ->whereHas('delivery', fn (Builder $query): Builder => $query
                ->where('stage', OperationStage::Done->value)
                ->where('completed_at', '<=', now()->subHours(6)));
    }

    public function confirmByAdmin(Shipment $shipment, User $user): Shipment
    {
        return $this->confirm($shipment, function (Shipment $locked) use ($user): void {
            $locked->confirmByAdmin($user);
        });
    }

    public function confirmByCustomer(Shipment $shipment, CustomerProfile $customer): Shipment
    {
        return $this->confirm($shipment, function (Shipment $locked) use ($customer): void {
            $locked->confirmByCustomer($customer);
        });
    }

    public function confirmBySystem(Shipment $shipment): Shipment
    {
        return $this->confirm($shipment, static function (Shipment $locked): void {
            $locked->confirmBySystem();
        });
    }

    /** @param callable(Shipment):void $confirmation */
    private function confirm(Shipment $shipment, callable $confirmation): Shipment
    {
        $arrived = DB::transaction(function () use ($shipment, $confirmation): Shipment {
            $locked = Shipment::query()->whereKey($shipment->getKey())->lockForUpdate()->sole();

            if ($locked->status !== ShipmentStatus::InTransit) {
                throw new DomainException('Shipment arrival requires an in-transit shipment.');
            }

            if (! $locked->delivery()->where('stage', OperationStage::Done->value)->exists()) {
                throw new DomainException('Shipment arrival requires a completed customer delivery.');
            }

            $confirmation($locked);

            return $locked->refresh();
        }, attempts: 5);

        $this->warrantyActivationService->activateForShipment($arrived);

        return $arrived;
    }
}
