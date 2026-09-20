<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerQuotationRequests\Pages;

use App\Filament\Resources\CustomerQuotationRequests\CustomerQuotationRequestResource;
use App\Models\CustomerDeliveryAddress;
use App\Models\CustomerProfile;
use App\Services\Crm\CustomerQuotationRequestService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateCustomerQuotationRequest extends CreateRecord
{
    protected static string $resource = CustomerQuotationRequestResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        $customerId = $data['customer_id'] ?? null;
        $customer = CustomerProfile::query()->findOrFail(is_numeric($customerId) ? (int) $customerId : 0);

        $deliveryAddressId = $data['customer_delivery_address_id'] ?? null;
        $deliveryAddress = is_numeric($deliveryAddressId)
            ? CustomerDeliveryAddress::query()->find((int) $deliveryAddressId)
            : null;

        $lines = [];

        foreach ((array) ($data['lines'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }

            $customerNote = $line['customer_note'] ?? null;
            $variantId = $line['product_variant_id'] ?? null;

            $lines[] = [
                'product_variant_id' => is_numeric($variantId) ? (int) $variantId : 0,
                'requested_quantity' => is_numeric($line['requested_quantity'] ?? null) ? $line['requested_quantity'] : 0,
                'customer_note' => is_string($customerNote) && $customerNote !== '' ? $customerNote : null,
            ];
        }

        $notes = $data['notes'] ?? null;

        return app(CustomerQuotationRequestService::class)->submit(
            customer: $customer,
            lines: $lines,
            deliveryAddress: $deliveryAddress,
            notes: is_string($notes) && $notes !== '' ? $notes : null,
        );
    }
}
