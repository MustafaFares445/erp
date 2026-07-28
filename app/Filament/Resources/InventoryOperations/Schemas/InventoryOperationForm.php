<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryOperations\Schemas;

use App\Models\InventoryOperation;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

final class InventoryOperationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('operation')
                ->tabs([
                    Tab::make(__('admin.inventory.operation.fields.operation_number'))
                        ->schema([
                            Section::make()->columns(2)->schema([
                                Select::make('operation_type')->options([
                                    'receipt' => __('admin.inventory.operation.types.receipt'),
                                    'delivery' => __('admin.inventory.operation.types.delivery'),
                                    'internal_transfer' => __('admin.inventory.operation.types.internal_transfer'),
                                ])->required()
                                    ->hintIcon(Heroicon::QuestionMarkCircle, 'Choose whether stock is received, delivered, or moved between warehouses. This controls the available workflow and stock effect.'),
                                Select::make('supplier_id')->relationship('supplier', 'name')->searchable()->preload()
                                    ->hintIcon(Heroicon::QuestionMarkCircle, 'Select the supplier when this operation records incoming stock from that supplier.'),
                                Select::make('source_warehouse_id')->relationship('sourceWarehouse', 'name')->searchable()->preload()
                                    ->hintIcon(Heroicon::QuestionMarkCircle, 'The source warehouse is where stock leaves for a delivery or internal transfer.'),
                                Select::make('destination_warehouse_id')->relationship('destinationWarehouse', 'name')->searchable()->preload()
                                    ->hintIcon(Heroicon::QuestionMarkCircle, 'The destination warehouse receives stock for a receipt or internal transfer.'),
                            ]),
                        ]),
                    Tab::make(__('admin.sections.operations'))
                        ->schema([
                            OperationStageBar::make(),
                            OperationLinesRepeater::make(),
                        ]),
                    Tab::make(__('admin.inventory.operation.fields.scheduled_at'))
                        ->schema([
                            DateTimePicker::make('scheduled_at'),
                            Select::make('responsible_id')->relationship('responsible', 'name')->searchable()->preload(),
                            TextInput::make('supplier_reference')->maxLength(100),
                        ]),
                    Tab::make(__('admin.inventory.operation.fields.notes'))
                        ->schema([
                            Textarea::make('notes')->columnSpanFull()->maxLength(5000),
                        ]),
                ])
                ->columnSpanFull(),
        ])->disabled(fn (?InventoryOperation $record): bool => $record?->isDraft() === false);
    }
}
