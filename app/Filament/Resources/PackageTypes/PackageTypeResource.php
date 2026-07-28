<?php

declare(strict_types=1);

namespace App\Filament\Resources\PackageTypes;

use App\Filament\Resources\PackageTypes\Pages\CreatePackageType;
use App\Filament\Resources\PackageTypes\Pages\EditPackageType;
use App\Filament\Resources\PackageTypes\Pages\ListPackageTypes;
use App\Filament\Resources\PackageTypes\Pages\ViewPackageType;
use App\Filament\Resources\PackageTypes\Schemas\PackageTypeForm;
use App\Filament\Resources\PackageTypes\Schemas\PackageTypeInfolist;
use App\Filament\Resources\PackageTypes\Tables\PackageTypesTable;
use App\Models\PackageType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class PackageTypeResource extends Resource
{
    protected static ?string $model = PackageType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.package_types');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return PackageTypeForm::configure($schema);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return PackageTypeInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return PackageTypesTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListPackageTypes::route('/'),
            'create' => CreatePackageType::route('/create'),
            'view' => ViewPackageType::route('/{record}'),
            'edit' => EditPackageType::route('/{record}/edit'),
        ];
    }

    #[\Override]
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
