<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductSubscriptions\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class ProductSubscriptionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name'),
                TextEntry::make('discount_type'),
                TextEntry::make('discount_value')->numeric(decimalPlaces: 2),
                TextEntry::make('visibility'),
                TextEntry::make('status')->badge(),
                TextEntry::make('valid_from')->date(),
                TextEntry::make('valid_until')->date(),
            ]);
    }
}
