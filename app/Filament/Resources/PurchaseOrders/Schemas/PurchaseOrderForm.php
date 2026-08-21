<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders\Schemas;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class PurchaseOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.resources.purchase_orders'))
                    ->schema([
                        TextInput::make('purchase_order_number')
                            ->label(__('admin.purchasing.fields.purchase_order_number'))
                            // Allocated inside the creating transaction by
                            // PurchaseOrderNumberGenerator, so it is shown but
                            // never submitted.
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (?PurchaseOrder $record): bool => $record instanceof PurchaseOrder),
                        Select::make('supplier_id')
                            ->label(__('admin.purchasing.fields.supplier'))
                            ->options(fn (): array => Supplier::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('destination_warehouse_id')
                            ->label(__('admin.purchasing.fields.destination_warehouse'))
                            ->options(fn (): array => Warehouse::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('currency_code')
                            ->label(__('admin.purchasing.fields.currency_code'))
                            ->required()
                            ->length(3)
                            ->default('AED')
                            ->extraInputAttributes(['style' => 'text-transform: uppercase']),
                        DatePicker::make('ordered_at')
                            ->label(__('admin.purchasing.fields.ordered_at'))
                            ->required()
                            ->default(today()),
                        DatePicker::make('expected_at')
                            ->label(__('admin.purchasing.fields.expected_at')),
                        Textarea::make('notes')
                            ->label(__('admin.purchasing.fields.notes'))
                            ->rows(2)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ])
            // Belt and braces over the policy, which already refuses `update` on
            // an order that has left draft, and over the service, which re-checks
            // the status itself. Three layers, because a silently-edited sent
            // order is a commitment the supplier never agreed to (FR-025).
            ->disabled(fn (?PurchaseOrder $record): bool => $record instanceof PurchaseOrder && ! $record->status->isEditable());
    }
}
