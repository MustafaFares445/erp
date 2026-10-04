<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportRoutingRules;

use App\Filament\Concerns\RequiresSupportFeature;
use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\SupportRoutingRules\Pages\CreateSupportRoutingRule;
use App\Filament\Resources\SupportRoutingRules\Pages\EditSupportRoutingRule;
use App\Filament\Resources\SupportRoutingRules\Pages\ListSupportRoutingRules;
use App\Filament\Resources\SupportRoutingRules\Schemas\SupportRoutingRuleForm;
use App\Filament\Resources\SupportRoutingRules\Tables\SupportRoutingRulesTable;
use App\Models\SupportRoutingRule;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class SupportRoutingRuleResource extends Resource
{
    use RequiresSupportFeature;

    protected static function supportFeatureFlag(): string
    {
        return 'support.smart_routing_enabled';
    }

    protected static ?string $model = SupportRoutingRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.support';

    protected static ?int $navigationSort = 707;

    protected static ?string $recordTitleAttribute = 'name';

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.support_routing_rules');
    }

    #[\Override]
    public static function getModelLabel(): string
    {
        return __('admin.resources.support_routing_rule');
    }

    #[\Override]
    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.support_routing_rules');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return SupportRoutingRuleForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return SupportRoutingRulesTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListSupportRoutingRules::route('/'),
            'create' => CreateSupportRoutingRule::route('/create'),
            'edit' => EditSupportRoutingRule::route('/{record}/edit'),
        ];
    }
}
