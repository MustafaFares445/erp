<?php

declare(strict_types=1);

namespace App\Filament\Resources\PriceHistories\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class PriceHistoryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('productVariant.sku')->label('SKU'),
            TextEntry::make('productVariant.name')->label('Variant'),
            TextEntry::make('cost_price')->money('USD'),
            TextEntry::make('markup_percent')->suffix('%'),
            TextEntry::make('base_price')->money('USD'),
            TextEntry::make('min_price')->money('USD'),
            TextEntry::make('changedBy.name')->label('Changed by'),
            TextEntry::make('created_at')->label('Changed at')->dateTime(),
        ]);
    }
}
