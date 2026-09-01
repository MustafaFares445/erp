<?php

declare(strict_types=1);

namespace App\Filament\Resources\Returns\Pages;

use App\Enums\InventoryReturnType;
use App\Filament\Resources\Returns\ReturnResource;
use App\Models\InventoryOperation;
use App\Models\InventoryReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryReturnService;
use DomainException;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use LogicException;

final class ManageReturns extends ManageRecords
{
    protected static string $resource = ReturnResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->using(function (array $data): InventoryReturn {
                    $actor = auth()->user();

                    if (! $actor instanceof User) {
                        throw new LogicException('An authenticated inventory return actor is required.');
                    }

                    $typeValue = $data['return_type'] ?? null;
                    $warehouseId = $data['warehouse_id'] ?? null;

                    if (! is_string($typeValue) || ! is_numeric($warehouseId)) {
                        throw new DomainException('A return type and warehouse are required.');
                    }

                    $type = InventoryReturnType::tryFrom($typeValue);
                    $warehouse = Warehouse::query()->findOrFail((int) $warehouseId);
                    $service = app(InventoryReturnService::class);
                    $reason = self::nullableString($data['reason'] ?? null);
                    $notes = self::nullableString($data['notes'] ?? null);

                    if ($type === InventoryReturnType::Customer) {
                        $deliveryId = $data['original_inventory_operation_id'] ?? null;

                        if (! is_numeric($deliveryId)) {
                            throw new DomainException('A customer return requires its original delivery.');
                        }

                        return $service->createCustomerReturn(
                            $actor,
                            InventoryOperation::query()->findOrFail((int) $deliveryId),
                            $warehouse,
                            $reason,
                            $notes,
                        );
                    }

                    if ($type === InventoryReturnType::Supplier) {
                        $supplierId = $data['supplier_id'] ?? null;

                        if (! is_numeric($supplierId)) {
                            throw new DomainException('A supplier return requires a supplier.');
                        }

                        $receiptId = $data['supplier_receipt_id'] ?? null;
                        $purchaseOrderId = $data['original_purchase_order_id'] ?? null;

                        return $service->createSupplierReturn(
                            $actor,
                            Supplier::query()->findOrFail((int) $supplierId),
                            $warehouse,
                            is_numeric($receiptId)
                                ? InventoryOperation::query()->findOrFail((int) $receiptId)
                                : null,
                            is_numeric($purchaseOrderId) ? (int) $purchaseOrderId : null,
                            $reason,
                            $notes,
                        );
                    }

                    throw new DomainException('The selected return type is invalid.');
                })
                ->successRedirectUrl(
                    fn (InventoryReturn $record): string => ReturnResource::getUrl('view', ['record' => $record]),
                ),
        ];
    }

    #[\Override]
    public function getSubheading(): string
    {
        return __('admin.inventory.return.list_notice');
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && mb_trim($value) !== '' ? mb_trim($value) : null;
    }
}
