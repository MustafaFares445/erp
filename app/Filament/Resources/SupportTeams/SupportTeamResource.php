<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportTeams;

use App\Filament\Concerns\RequiresSupportFeature;
use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\SupportTeams\Pages\CreateSupportTeam;
use App\Filament\Resources\SupportTeams\Pages\EditSupportTeam;
use App\Filament\Resources\SupportTeams\Pages\ListSupportTeams;
use App\Filament\Resources\SupportTeams\RelationManagers\MembersRelationManager;
use App\Filament\Resources\SupportTeams\Schemas\SupportTeamForm;
use App\Filament\Resources\SupportTeams\Tables\SupportTeamsTable;
use App\Models\SupportTeam;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class SupportTeamResource extends Resource
{
    use RequiresSupportFeature;

    protected static function supportFeatureFlag(): string
    {
        return 'support.smart_routing_enabled';
    }

    protected static ?string $model = SupportTeam::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.support';

    protected static ?int $navigationSort = 705;

    protected static ?string $recordTitleAttribute = 'name';

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.support_teams');
    }

    #[\Override]
    public static function getModelLabel(): string
    {
        return __('admin.resources.support_team');
    }

    #[\Override]
    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.support_teams');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return SupportTeamForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return SupportTeamsTable::configure($table);
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [MembersRelationManager::class];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListSupportTeams::route('/'),
            'create' => CreateSupportTeam::route('/create'),
            'edit' => EditSupportTeam::route('/{record}/edit'),
        ];
    }
}
