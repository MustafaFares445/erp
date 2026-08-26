<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentTerms\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

final class PaymentTermForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('admin.sales.fields.name'))
                ->required()
                ->maxLength(100)
                ->unique(ignoreRecord: true),
            TextInput::make('due_days')
                ->label(__('admin.sales.fields.due_days'))
                ->numeric()
                ->minValue(0)
                ->required()
                ->default(0),
            TextInput::make('grace_days')
                ->label(__('admin.sales.fields.grace_days'))
                ->numeric()
                ->minValue(0)
                ->required()
                ->default(0),
            TextInput::make('discount_percent')
                ->label(__('admin.sales.fields.discount_percent'))
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->step(0.01),
            Toggle::make('is_default')
                ->label(__('admin.sales.fields.is_default')),
        ])->columns(2);
    }
}
