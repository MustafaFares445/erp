<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerReturnRequests\Schemas;

use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\ProductVariant;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * The optional "submit on behalf of a customer" path (e.g. a phone call),
 * for staff use only — the primary channel is the future customer app.
 */
final class CustomerReturnRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Select::make('customer_id')
                            ->label('Customer')
                            ->relationship(name: 'customer', titleAttribute: 'company_name')
                            ->searchable()
                            ->preload()
                            ->live()
                            ->required(),
                        Select::make('original_inventory_operation_id')
                            ->label('Delivery being returned')
                            ->options(fn (Get $get): array => InventoryOperation::query()
                                ->where('operation_type', OperationType::Delivery->value)
                                ->where('stage', OperationStage::Done->value)
                                ->where('customer_id', $get('customer_id'))
                                ->pluck('operation_number', 'id')
                                ->all())
                            ->searchable()
                            ->live()
                            ->required(),
                        Textarea::make('reason')->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Items to return')
                    ->schema([
                        Repeater::make('lines')
                            ->label('Lines')
                            ->schema([
                                Select::make('original_inventory_operation_line_id')
                                    ->label('Delivered line')
                                    ->options(fn (Get $get): array => InventoryOperationLine::query()
                                        ->where('inventory_operation_id', $get('../../original_inventory_operation_id'))
                                        ->with('productVariant')
                                        ->get()
                                        ->mapWithKeys(function (InventoryOperationLine $line): array {
                                            $key = $line->getKey();
                                            $variant = $line->productVariant;
                                            $sku = $variant instanceof ProductVariant ? $variant->sku : '—';

                                            return [
                                                (is_numeric($key) ? (int) $key : 0) => sprintf('%s (qty %s)', $sku, $line->quantity),
                                            ];
                                        })
                                        ->all())
                                    ->searchable()
                                    ->required(),
                                TextInput::make('requested_quantity')
                                    ->numeric()
                                    ->minValue(0.000001)
                                    ->step(0.000001)
                                    ->required(),
                                Textarea::make('customer_note')->rows(1),
                            ])
                            ->columns(3)
                            ->defaultItems(1)
                            ->minItems(1)
                            ->required()
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
