<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerPricingTiers\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class CustomerPricingTierInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('customer.name')->label('Customer'),
            TextEntry::make('customer.email')->label('Customer email'),
            TextEntry::make('pricingTier.name')->label('General tier'),
            TextEntry::make('pricingTier.discount_percent')->label('Discount')->suffix('%'),
            IconEntry::make('is_active')->boolean(),
            TextEntry::make('created_at')->dateTime(),
            TextEntry::make('updated_at')->dateTime(),
        ]);
    }
}
