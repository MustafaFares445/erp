<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesOpportunities;

use App\Filament\Resources\SalesOpportunities\Pages\ListSalesOpportunities;
use App\Filament\Resources\SalesOpportunities\Pages\ViewSalesOpportunity;
use App\Filament\Resources\SalesOpportunities\Schemas\SalesOpportunityInfolist;
use App\Filament\Resources\SalesOpportunities\Tables\SalesOpportunitiesTable;
use App\Models\SalesOpportunity;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class SalesOpportunityResource extends Resource
{
    protected static ?string $model = SalesOpportunity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.employees';

    protected static ?int $navigationSort = 632;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.sales_opportunity');
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return SalesOpportunityInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return SalesOpportunitiesTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListSalesOpportunities::route('/'),
            'view' => ViewSalesOpportunity::route('/{record}'),
        ];
    }
}
