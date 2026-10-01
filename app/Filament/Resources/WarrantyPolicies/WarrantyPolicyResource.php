<?php

declare(strict_types=1);

namespace App\Filament\Resources\WarrantyPolicies;

use App\Filament\Resources\WarrantyPolicies\Pages\CreateWarrantyPolicy;
use App\Filament\Resources\WarrantyPolicies\Pages\EditWarrantyPolicy;
use App\Filament\Resources\WarrantyPolicies\Pages\ListWarrantyPolicies;
use App\Filament\Resources\WarrantyPolicies\Pages\ViewWarrantyPolicy;
use App\Filament\Resources\WarrantyPolicies\Schemas\WarrantyPolicyForm;
use App\Filament\Resources\WarrantyPolicies\Schemas\WarrantyPolicyInfolist;
use App\Filament\Resources\WarrantyPolicies\Tables\WarrantyPoliciesTable;
use App\Models\WarrantyPolicy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

final class WarrantyPolicyResource extends Resource
{
    protected static ?string $model = WarrantyPolicy::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.support';

    protected static ?int $navigationSort = 705;

    protected static ?string $recordTitleAttribute = 'name';

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return 'Warranty Policies';
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return WarrantyPolicyForm::configure($schema);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return WarrantyPolicyInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return WarrantyPoliciesTable::configure($table);
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListWarrantyPolicies::route('/'),
            'create' => CreateWarrantyPolicy::route('/create'),
            'view' => ViewWarrantyPolicy::route('/{record}'),
            'edit' => EditWarrantyPolicy::route('/{record}/edit'),
        ];
    }
}
