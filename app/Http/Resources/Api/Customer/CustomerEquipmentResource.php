<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\Customer;

use App\Enums\SupportEntitlementStatus;
use App\Models\SerializedInventoryUnit;
use App\Models\SupportEntitlement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SerializedInventoryUnit */
final class CustomerEquipmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(Request $request): array
    {
        $entitlement = $this->currentWarrantyEntitlement;
        $today = today();
        $supportEntitlement = $this->supportEntitlements
            ->first(static fn (SupportEntitlement $item): bool => $item->status === SupportEntitlementStatus::Active
                && $item->starts_on->lte($today)
                && ($item->ends_on === null || $item->ends_on->gte($today)));

        return [
            'id' => $this->getKey(),
            'serial_number' => $this->serial_number,
            'iot_number' => $this->iot_number,
            'product' => [
                'name' => $this->productVariant?->product?->name,
                'variant' => $this->productVariant?->name,
                'sku' => $this->productVariant?->sku,
            ],
            'condition' => $this->stock_condition->value,
            'warranty' => [
                'state' => $entitlement?->state?->value,
                'policy_name' => $entitlement?->policy_name,
                'starts_on' => $entitlement?->starts_on?->toDateString() ?? $this->warranty_started_on?->toDateString(),
                'expires_on' => $entitlement?->expires_on?->toDateString() ?? $this->warranty_expires_on?->toDateString(),
            ],
            'service_level' => $supportEntitlement?->serviceLevel?->name,
            'next_preventive_due_on' => $this->maintenanceSchedules
                ->where('is_active', true)
                ->whereNotNull('next_due_on')
                ->sortBy('next_due_on')
                ->first()?->next_due_on?->toDateString(),
        ];
    }
}
