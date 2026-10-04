<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Customer;

use App\Http\Resources\Api\Customer\CustomerEquipmentResource;
use App\Models\CustomerProfile;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CustomerEquipmentController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $equipment = $this->customer($request)
            ->ownedEquipment()
            ->with([
                'productVariant.product',
                'currentWarrantyEntitlement',
                'supportEntitlements.serviceLevel',
                'maintenanceSchedules',
            ])
            ->orderBy('serial_number')
            ->paginate(30);

        return CustomerEquipmentResource::collection($equipment);
    }

    public function show(Request $request, SerializedInventoryUnit $equipment): CustomerEquipmentResource
    {
        abort_unless(
            $this->customer($request)->ownedEquipment()->whereKey($equipment->getKey())->exists(),
            404,
        );

        $equipment->load([
            'productVariant.product',
            'currentWarrantyEntitlement',
            'supportEntitlements.serviceLevel',
            'maintenanceSchedules',
        ]);

        return new CustomerEquipmentResource($equipment);
    }

    private function customer(Request $request): CustomerProfile
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->customerProfile instanceof CustomerProfile, 403);

        return $user->customerProfile;
    }
}
