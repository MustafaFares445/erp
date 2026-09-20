<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerQuotationRequests\Schemas;

use App\Models\CustomerDeliveryAddress;
use App\Models\ProductVariant;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * The optional "create on behalf of a customer" path (e.g. a phone order),
 * for staff use only — the primary channel is the future customer app.
 */
final class CustomerQuotationRequestForm
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
                        Select::make('customer_delivery_address_id')
                            ->label('Delivery address')
                            ->options(fn (Get $get): array => CustomerDeliveryAddress::query()
                                ->where('customer_profile_id', $get('customer_id'))
                                ->where('is_active', true)
                                ->pluck('label', 'id')
                                ->all())
                            ->searchable(),
                        Textarea::make('notes')->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Requested products')
                    ->schema([
                        Repeater::make('lines')
                            ->label('Lines')
                            ->schema([
                                Select::make('product_variant_id')
                                    ->label('Product variant')
                                    ->options(fn (): array => ProductVariant::query()
                                        ->where('is_active', true)
                                        ->orderBy('sku')
                                        ->pluck('sku', 'id')
                                        ->all())
                                    ->searchable()
                                    ->preload()
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
