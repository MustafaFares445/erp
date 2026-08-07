<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiKeywordRules\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

final class AiKeywordRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('keyword')
                    ->required()
                    ->maxLength(150),
                Select::make('product_id')
                    ->label('Linked product')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload(),
                Select::make('product_variant_id')
                    ->label('Linked variant')
                    ->relationship('productVariant', 'sku')
                    ->searchable()
                    ->preload(),
                Toggle::make('is_active')
                    ->default(true),
            ])
            ->columns(2);
    }
}
