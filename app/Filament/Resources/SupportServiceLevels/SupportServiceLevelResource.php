<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportServiceLevels;

use App\Filament\Concerns\RequiresSupportFeature;
use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\SupportServiceLevels\Pages\CreateSupportServiceLevel;
use App\Filament\Resources\SupportServiceLevels\Pages\EditSupportServiceLevel;
use App\Filament\Resources\SupportServiceLevels\Pages\ListSupportServiceLevels;
use App\Filament\Resources\SupportServiceLevels\Schemas\SupportServiceLevelForm;
use App\Filament\Resources\SupportServiceLevels\Tables\SupportServiceLevelsTable;
use App\Models\SupportServiceLevel;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class SupportServiceLevelResource extends Resource
{
    use RequiresSupportFeature;

    protected static ?string $model = SupportServiceLevel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.support';

    protected static ?int $navigationSort = 706;

    protected static ?string $recordTitleAttribute = 'name';

    #[\Override]
    protected static function supportFeatureFlag(): string
    {
        return 'support.sla_v2_enabled';
    }

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.support_service_levels');
    }

    #[\Override]
    public static function getModelLabel(): string
    {
        return __('admin.resources.support_service_level');
    }

    #[\Override]
    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.support_service_levels');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return SupportServiceLevelForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return SupportServiceLevelsTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListSupportServiceLevels::route('/'),
            'create' => CreateSupportServiceLevel::route('/create'),
            'edit' => EditSupportServiceLevel::route('/{record}/edit'),
        ];
    }
}
