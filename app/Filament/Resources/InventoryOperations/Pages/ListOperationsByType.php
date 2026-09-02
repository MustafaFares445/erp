<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryOperations\Pages;

use App\Enums\OperationType;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Models\InventoryOperation;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

abstract class ListOperationsByType extends ListRecords
{
    protected static string $resource = InventoryOperationResource::class;

    abstract protected static function operationType(): OperationType;

    #[\Override]
    public static function canAccess(array $parameters = []): bool
    {
        return InventoryOperationResource::canViewOperationType(static::operationType());
    }

    /** @return Builder<InventoryOperation> */
    #[\Override]
    protected function getTableQuery(): Builder
    {
        return InventoryOperation::query()
            ->where('operation_type', static::operationType()->value);
    }

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn (): bool => InventoryOperationResource::canCreateOperationType(static::operationType()))
                ->mutateDataUsing(fn (array $data): array => [
                    ...$data,
                    'operation_type' => static::operationType()->value,
                ])
                ->url(InventoryOperationResource::getUrl('create', [
                    'operation_type' => static::operationType()->value,
                ])),
        ];
    }

    #[\Override]
    public function getTitle(): string
    {
        return match (static::operationType()) {
            OperationType::Receipt => __('admin.resources.inventory_receipts_menu'),
            OperationType::Delivery => __('admin.resources.inventory_deliveries'),
            OperationType::InternalTransfer => __('admin.resources.internal_transfers'),
        };
    }

    #[\Override]
    public function getSubheading(): string
    {
        return match (static::operationType()) {
            OperationType::Receipt => __('admin.inventory.operation.receipt_list_notice'),
            OperationType::Delivery => __('admin.inventory.operation.delivery_list_notice'),
            OperationType::InternalTransfer => __('admin.inventory.operation.internal_transfer_list_notice'),
        };
    }
}
