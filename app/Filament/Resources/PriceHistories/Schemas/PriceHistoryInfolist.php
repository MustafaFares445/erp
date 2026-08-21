<?php

declare(strict_types=1);

namespace App\Filament\Resources\PriceHistories\Schemas;

use App\Enums\PriceChangeRequestStatus;
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
            TextEntry::make('status')
                ->badge()
                ->color(fn (PriceChangeRequestStatus $state): string => match ($state) {
                    PriceChangeRequestStatus::Pending => 'warning',
                    PriceChangeRequestStatus::Approved => 'success',
                    PriceChangeRequestStatus::Rejected => 'danger',
                }),
            TextEntry::make('changedBy.name')->label('Requested by'),
            TextEntry::make('created_at')->label('Requested at')->dateTime(),
            TextEntry::make('reviewedBy.name')->label('Reviewed by')->placeholder('—'),
            TextEntry::make('reviewed_at')->label('Reviewed at')->dateTime()->placeholder('—'),
        ]);
    }
}
