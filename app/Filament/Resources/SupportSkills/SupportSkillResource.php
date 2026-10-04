<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportSkills;

use App\Filament\Concerns\RequiresSupportFeature;
use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\SupportSkills\Pages\CreateSupportSkill;
use App\Filament\Resources\SupportSkills\Pages\EditSupportSkill;
use App\Filament\Resources\SupportSkills\Pages\ListSupportSkills;
use App\Filament\Resources\SupportSkills\Schemas\SupportSkillForm;
use App\Filament\Resources\SupportSkills\Tables\SupportSkillsTable;
use App\Models\SupportSkill;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class SupportSkillResource extends Resource
{
    use RequiresSupportFeature;

    protected static function supportFeatureFlag(): string
    {
        return 'support.smart_routing_enabled';
    }

    protected static ?string $model = SupportSkill::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.support';

    protected static ?int $navigationSort = 706;

    protected static ?string $recordTitleAttribute = 'name';

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.support_skills');
    }

    #[\Override]
    public static function getModelLabel(): string
    {
        return __('admin.resources.support_skill');
    }

    #[\Override]
    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.support_skills');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return SupportSkillForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return SupportSkillsTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListSupportSkills::route('/'),
            'create' => CreateSupportSkill::route('/create'),
            'edit' => EditSupportSkill::route('/{record}/edit'),
        ];
    }
}
