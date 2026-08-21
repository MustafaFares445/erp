<?php

declare(strict_types=1);

namespace App\Filament\Resources\PriceFloorOverrides;

use App\Filament\Resources\PriceFloorOverrides\Pages\ListPriceFloorOverrides;
use App\Filament\Resources\PriceFloorOverrides\Pages\ViewPriceFloorOverride;
use App\Filament\Resources\PriceFloorOverrides\Schemas\PriceFloorOverrideInfolist;
use App\Filament\Resources\PriceFloorOverrides\Tables\PriceFloorOverridesTable;
use App\Models\PriceFloorOverride;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class PriceFloorOverrideResource extends Resource
{
    protected static ?string $model = PriceFloorOverride::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.price_floor_overrides');
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return PriceFloorOverrideInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return PriceFloorOverridesTable::configure($table);
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'productVariant:id,sku,name',
                'customer:id,name',
                'pricingTier:id,name',
                'approvedBy:id,name',
            ])
            ->latest('approved_at');
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListPriceFloorOverrides::route('/'),
            'view' => ViewPriceFloorOverride::route('/{record}'),
        ];
    }
}
