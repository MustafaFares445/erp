<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryOperations\Schemas;

use App\Enums\OperationStage;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class InventoryOperationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextEntry::make('operation_number')->label(__('admin.inventory.operation.fields.operation_number'))->placeholder(__('admin.inventory.adjustment.number_pending')),
                TextEntry::make('stage')->badge()->formatStateUsing(function (mixed $state): string {
                    if ($state instanceof OperationStage) {
                        return $state->label();
                    }

                    return is_scalar($state) ? (string) $state : '';
                }),
                TextEntry::make('sourceWarehouse.name')->label(__('admin.inventory.operation.fields.source_warehouse')),
                TextEntry::make('destinationWarehouse.name')->label(__('admin.inventory.operation.fields.destination_warehouse')),
                TextEntry::make('supplier.name')->label(__('admin.inventory.operation.fields.supplier')),
                TextEntry::make('scheduled_at')->label(__('admin.inventory.operation.fields.scheduled_at'))->dateTime(),
                TextEntry::make('notes')->label(__('admin.inventory.operation.fields.notes'))->columnSpanFull(),
            ]),
            Section::make(__('admin.sections.operations'))->schema([
                RepeatableEntry::make('lines')->label('')->columns(4)->schema([
                    TextEntry::make('productVariant.sku')->label(__('admin.inventory.operation.fields.product')),
                    TextEntry::make('quantity')->label(__('admin.inventory.operation.fields.demand')),
                    TextEntry::make('unit.name')->label(__('admin.inventory.operation.fields.unit')),
                    TextEntry::make('is_picked')->label(__('admin.inventory.operation.fields.picked'))->badge(),
                ]),
            ]),
        ]);
    }
}
