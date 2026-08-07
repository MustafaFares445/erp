<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiKeywordRules;

use App\Filament\Resources\AiKeywordRules\Pages\CreateAiKeywordRule;
use App\Filament\Resources\AiKeywordRules\Pages\EditAiKeywordRule;
use App\Filament\Resources\AiKeywordRules\Pages\ListAiKeywordRules;
use App\Filament\Resources\AiKeywordRules\Schemas\AiKeywordRuleForm;
use App\Filament\Resources\AiKeywordRules\Tables\AiKeywordRulesTable;
use App\Models\AiKeywordRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class AiKeywordRuleResource extends Resource
{
    protected static ?string $model = AiKeywordRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.employees';

    protected static ?int $navigationSort = 631;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.ai_keyword_rules');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return AiKeywordRuleForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return AiKeywordRulesTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListAiKeywordRules::route('/'),
            'create' => CreateAiKeywordRule::route('/create'),
            'edit' => EditAiKeywordRule::route('/{record}/edit'),
        ];
    }
}
