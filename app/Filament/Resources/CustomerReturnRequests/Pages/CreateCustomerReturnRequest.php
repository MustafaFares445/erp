<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerReturnRequests\Pages;

use App\Filament\Resources\CustomerReturnRequests\CustomerReturnRequestResource;
use App\Models\CustomerProfile;
use App\Models\InventoryOperation;
use App\Services\Crm\CustomerReturnRequestService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateCustomerReturnRequest extends CreateRecord
{
    protected static string $resource = CustomerReturnRequestResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        $customerId = $data['customer_id'] ?? null;
        $customer = CustomerProfile::query()->findOrFail(is_numeric($customerId) ? (int) $customerId : 0);

        $deliveryId = $data['original_inventory_operation_id'] ?? null;
        $delivery = InventoryOperation::query()->findOrFail(is_numeric($deliveryId) ? (int) $deliveryId : 0);

        $lines = [];

        foreach ((array) ($data['lines'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }

            $customerNote = $line['customer_note'] ?? null;
            $lineId = $line['original_inventory_operation_line_id'] ?? null;

            $lines[] = [
                'original_inventory_operation_line_id' => is_numeric($lineId) ? (int) $lineId : 0,
                'requested_quantity' => is_numeric($line['requested_quantity'] ?? null) ? $line['requested_quantity'] : 0,
                'customer_note' => is_string($customerNote) && $customerNote !== '' ? $customerNote : null,
            ];
        }

        $reason = $data['reason'] ?? null;

        return app(CustomerReturnRequestService::class)->submit(
            customer: $customer,
            delivery: $delivery,
            lines: $lines,
            reason: is_string($reason) && $reason !== '' ? $reason : null,
        );
    }
}
