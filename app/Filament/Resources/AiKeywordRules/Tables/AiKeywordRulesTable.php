<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiKeywordRules\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

final class AiKeywordRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('keyword')
            ->columns([
                TextColumn::make('keyword')->searchable()->sortable(),
                TextColumn::make('product.name')->label('Product')->placeholder('Not linked'),
                TextColumn::make('productVariant.sku')->label('Variant')->placeholder('Not linked'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ]);
    }
}
