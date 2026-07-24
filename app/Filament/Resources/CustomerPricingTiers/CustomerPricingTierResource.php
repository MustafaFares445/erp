<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerPricingTiers;

use App\Filament\Resources\CustomerPricingTiers\Pages\ListCustomerPricingTiers;
use App\Filament\Resources\CustomerPricingTiers\Pages\ViewCustomerPricingTier;
use App\Filament\Resources\CustomerPricingTiers\Schemas\CustomerPricingTierInfolist;
use App\Filament\Resources\CustomerPricingTiers\Tables\CustomerPricingTiersTable;
use App\Models\CustomerPricingTier;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class CustomerPricingTierResource extends Resource
{
    protected static ?string $model = CustomerPricingTier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.customer_pricing_tiers');
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return CustomerPricingTierInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return CustomerPricingTiersTable::configure($table);
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['customer:id,name,email', 'pricingTier:id,name,discount_percent'])
            ->latest('id');
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListCustomerPricingTiers::route('/'),
            'view' => ViewCustomerPricingTier::route('/{record}'),
        ];
    }
}
