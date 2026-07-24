<?php

declare(strict_types=1);

namespace App\Filament\Resources\PriceFloorOverrides\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class PriceFloorOverrideInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('productVariant.sku')->label('SKU'),
            TextEntry::make('productVariant.name')->label('Variant'),
            TextEntry::make('customer.name')->label('Customer')->placeholder('General'),
            TextEntry::make('attempted_price')->money('USD'),
            TextEntry::make('min_price')->label('Captured floor')->money('USD'),
            TextEntry::make('approvedBy.name')->label('Approved by'),
            TextEntry::make('approved_at')->dateTime(),
            TextEntry::make('reason')->columnSpanFull(),
        ]);
    }
}
