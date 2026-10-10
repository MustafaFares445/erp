<?php

declare(strict_types=1);

namespace App\Filament\Resources\PriceFloorOverrides\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

final class PriceFloorOverrideInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('productVariant.sku')->label(__('SKU')),
            TextEntry::make('productVariant.name')->label(__('Variant')),
            TextEntry::make('customer.name')->label(__('Customer'))->placeholder(__('General')),
            TextEntry::make('pricingTier.name')->label(__('Pricing tier'))->placeholder(__('Base or manual price')),
            TextEntry::make('attempted_price')->money(),
            TextEntry::make('min_price')->label(__('Captured floor'))->money(),
            TextEntry::make('approvedBy.name')->label(__('Approved by')),
            TextEntry::make('approved_at')->dateTime(),
            TextEntry::make('reason')
                ->formatStateUsing(static fn (string $state): HtmlString => new HtmlString('<bdi>'.e($state).'</bdi>'))
                ->columnSpanFull(),
        ]);
    }
}
