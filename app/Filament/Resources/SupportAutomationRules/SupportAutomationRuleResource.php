<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportAutomationRules;

use App\Filament\Concerns\RequiresSupportFeature;
use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\SupportAutomationRules\Pages\CreateSupportAutomationRule;
use App\Filament\Resources\SupportAutomationRules\Pages\EditSupportAutomationRule;
use App\Filament\Resources\SupportAutomationRules\Pages\ListSupportAutomationRules;
use App\Filament\Resources\SupportAutomationRules\Schemas\SupportAutomationRuleForm;
use App\Filament\Resources\SupportAutomationRules\Tables\SupportAutomationRulesTable;
use App\Models\SupportAutomationRule;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class SupportAutomationRuleResource extends Resource
{
    use RequiresSupportFeature;

    protected static function supportFeatureFlag(): string
    {
        return 'support.support_automation_enabled';
    }

    protected static ?string $model = SupportAutomationRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.support';

    protected static ?int $navigationSort = 709;

    protected static ?string $recordTitleAttribute = 'name';

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.support_automation_rules');
    }

    #[\Override]
    public static function getModelLabel(): string
    {
        return __('admin.resources.support_automation_rule');
    }

    #[\Override]
    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.support_automation_rules');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return SupportAutomationRuleForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return SupportAutomationRulesTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListSupportAutomationRules::route('/'),
            'create' => CreateSupportAutomationRule::route('/create'),
            'edit' => EditSupportAutomationRule::route('/{record}/edit'),
        ];
    }
}
