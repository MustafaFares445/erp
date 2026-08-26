<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierConfirmations\Pages;

use App\Data\Purchasing\SupplierConfirmationRequestData;
use App\Filament\Concerns\InteractsWithPurchasingServices;
use App\Filament\Resources\SupplierConfirmations\SupplierConfirmationResource;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\SupplierConfirmation;
use App\Models\User;
use App\Services\Purchasing\SupplierConfirmationService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

final class ManageSupplierConfirmations extends ManageRecords
{
    use InteractsWithPurchasingServices;

    protected static string $resource = SupplierConfirmationResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->using(function (array $data): SupplierConfirmation {
                $actor = self::purchasingActor();

                if (! $actor instanceof User) {
                    throw new Halt;
                }

                return self::runPurchasingOperation(
                    fn (): SupplierConfirmation => app(SupplierConfirmationService::class)->recordItems(
                        $actor,
                        new SupplierConfirmationRequestData(
                            target: $this->targetFrom($data),
                            customer: $this->customerFrom($data),
                            supplierId: self::integerFrom($data['supplier_id'] ?? null),
                            items: $this->itemsFrom($data),
                            notes: self::nullableStringFrom($data['notes'] ?? null),
                        ),
                    ),
                    'admin.purchasing.notifications.confirmation_recorded',
                );
            }),
        ];
    }

    /** @param array<array-key, mixed> $data */
    private function targetFrom(array $data): ?Model
    {
        $targets = [
            PurchaseOrder::class => self::nullableIntegerFrom($data['purchase_order_id'] ?? null),
            Order::class => self::nullableIntegerFrom($data['order_id'] ?? null),
            Quotation::class => self::nullableIntegerFrom($data['quotation_id'] ?? null),
        ];
        $targets = array_filter($targets);

        if (count($targets) > 1) {
            throw new Halt;
        }

        if ($targets === []) {
            return null;
        }

        $type = array_key_first($targets);
        $id = $targets[$type];

        return $type::query()->findOrFail($id);
    }

    /** @param array<array-key, mixed> $data */
    private function customerFrom(array $data): ?CustomerProfile
    {
        $customerId = self::nullableIntegerFrom($data['customer_id'] ?? null);

        return $customerId === null ? null : CustomerProfile::query()->findOrFail($customerId);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<array{product_variant_id: int, requested_quantity: float, notes?: string|null}>
     */
    private function itemsFrom(array $data): array
    {
        if (! is_array($data['items'] ?? null)) {
            return [];
        }

        $items = [];

        foreach ($data['items'] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $quantity = $item['requested_quantity'] ?? 0;

            $items[] = [
                'product_variant_id' => self::integerFrom($item['product_variant_id'] ?? null),
                'requested_quantity' => is_numeric($quantity) ? (float) $quantity : 0.0,
                'notes' => self::nullableStringFrom($item['notes'] ?? null),
            ];
        }

        return $items;
    }
}
