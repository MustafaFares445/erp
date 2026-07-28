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
            CreateAction::make()->mutateDataUsing(function (array $data): array {
                $data['operation_type'] = static::operationType()->value;

                return $data;
            }),
        ];
    }
}
