<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Concerns\InteractsWithPurchasingServices;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Purchasing\PurchaseOrderService;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * Creates through {@see PurchaseOrderService::createDraft()} rather than letting
 * Filament write the row, so the order number is allocated by the one method
 * that knows how and the supplier and warehouse are validated by the service
 * rather than only by the form (R-G).
 */
final class CreatePurchaseOrder extends CreateRecord
{
    use InteractsWithPurchasingServices;

    protected static string $resource = PurchaseOrderResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        $actor = self::purchasingActor();

        if (! $actor instanceof User) {
            throw new Halt;
        }

        return self::runPurchasingOperation(
            fn (): PurchaseOrder => app(PurchaseOrderService::class)->createDraft($actor, [
                'supplier_id' => self::integerFrom($data['supplier_id'] ?? null),
                'destination_warehouse_id' => self::integerFrom($data['destination_warehouse_id'] ?? null),
                'currency_code' => self::stringFrom($data['currency_code'] ?? 'AED'),
                'ordered_at' => self::stringFrom($data['ordered_at'] ?? null),
                'expected_at' => self::nullableStringFrom($data['expected_at'] ?? null),
                'notes' => self::nullableStringFrom($data['notes'] ?? null),
            ]),
        );
    }
}
