<?php

declare(strict_types=1);

namespace App\Services\Shipments;

use App\Models\CustomerProfile;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Support\WarrantyActivationService;
use Illuminate\Database\Eloquent\Builder;

final class ShipmentService
{
    public function __construct(private readonly WarrantyActivationService $warrantyActivationService) {}

    /** @return Builder<Shipment> */
    public function eligibleForAutomaticArrival(): Builder
    {
        return Shipment::query()
            ->where('status', 'in_transit')
            ->where('created_at', '<=', now()->subHours(6));
    }

    public function confirmByAdmin(Shipment $shipment, User $user): Shipment
    {
        $shipment->confirmByAdmin($user);
        $shipment = $shipment->refresh();
        $this->warrantyActivationService->activateForShipment($shipment);

        return $shipment;
    }

    public function confirmByCustomer(Shipment $shipment, CustomerProfile $customer): Shipment
    {
        $shipment->confirmByCustomer($customer);
        $shipment = $shipment->refresh();
        $this->warrantyActivationService->activateForShipment($shipment);

        return $shipment;
    }

    public function confirmBySystem(Shipment $shipment): Shipment
    {
        $shipment->confirmBySystem();
        $shipment = $shipment->refresh();
        $this->warrantyActivationService->activateForShipment($shipment);

        return $shipment;
    }
}
