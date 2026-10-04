<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Customer;

use App\Http\Resources\Api\Customer\CustomerMaintenanceResource;
use App\Models\CustomerProfile;
use App\Models\MaintenanceRecord;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CustomerMaintenanceController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $records = MaintenanceRecord::query()
            ->where('customer_id', $this->customer($request)->getKey())
            ->with($this->resourceRelations())
            ->latest('id')
            ->paginate(20);

        return CustomerMaintenanceResource::collection($records);
    }

    public function show(Request $request, MaintenanceRecord $maintenance): CustomerMaintenanceResource
    {
        abort_unless($maintenance->customer_id === $this->customer($request)->getKey(), 404);
        $maintenance->load($this->resourceRelations());

        return new CustomerMaintenanceResource($maintenance);
    }

    /** @return list<string> */
    private function resourceRelations(): array
    {
        return [
            'serializedInventoryUnit.productVariant',
            'productVariant',
            'coverageLines',
            'quotation',
            'invoice',
            'serviceRecords.serviceAppointments',
        ];
    }

    private function customer(Request $request): CustomerProfile
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->customerProfile instanceof CustomerProfile, 403);

        return $user->customerProfile;
    }
}
