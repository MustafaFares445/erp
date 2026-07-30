<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductSubscriptions\Schemas;

use App\Enums\ProductSubscriptionDiscountType;
use App\Enums\ProductSubscriptionVisibility;
use App\Filament\Resources\ProductSubscriptions\ProductSubscriptionResource;
use App\Models\ProductSubscription;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class ProductSubscriptionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Subscription terms')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(150)
                            ->unique(ProductSubscription::class, 'name', ignoreRecord: true)
                            ->disabled(fn (): bool => ! ProductSubscriptionResource::canManage()),
                        Select::make('discount_type')
                            ->options([
                                ProductSubscriptionDiscountType::Percentage->value => 'Percentage',
                                ProductSubscriptionDiscountType::Fixed->value => 'Fixed amount',
                            ])
                            ->required(),
                        TextInput::make('discount_value')
                            ->numeric()
                            ->minValue(0.01)
                            ->step(0.01)
                            ->required(),
                        Select::make('visibility')
                            ->options([
                                ProductSubscriptionVisibility::Public->value => 'Public',
                                ProductSubscriptionVisibility::Restricted->value => 'Restricted',
                            ])
                            ->required()
                            ->disabled(fn (): bool => ! ProductSubscriptionResource::canManage()),
                        DatePicker::make('valid_from')
                            ->disabled(fn (): bool => ! ProductSubscriptionResource::canManage()),
                        DatePicker::make('valid_until')
                            ->afterOrEqual('valid_from')
                            ->disabled(fn (): bool => ! ProductSubscriptionResource::canManage()),
                    ])
                    ->columns(2),
            ]);
    }
}
