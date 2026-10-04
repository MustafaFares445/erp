<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportEntitlements;

use App\Filament\Concerns\RequiresSupportFeature;
use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\SupportEntitlements\Pages\CreateSupportEntitlement;
use App\Filament\Resources\SupportEntitlements\Pages\EditSupportEntitlement;
use App\Filament\Resources\SupportEntitlements\Pages\ListSupportEntitlements;
use App\Filament\Resources\SupportEntitlements\Schemas\SupportEntitlementForm;
use App\Filament\Resources\SupportEntitlements\Tables\SupportEntitlementsTable;
use App\Models\SupportEntitlement;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class SupportEntitlementResource extends Resource
{
    use RequiresSupportFeature;

    protected static ?string $model = SupportEntitlement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.support';

    protected static ?int $navigationSort = 707;

    protected static ?string $recordTitleAttribute = 'external_reference';

    #[\Override]
    protected static function supportFeatureFlag(): string
    {
        return 'support.sla_v2_enabled';
    }

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.support_entitlements');
    }

    #[\Override]
    public static function getModelLabel(): string
    {
        return __('admin.resources.support_entitlement');
    }

    #[\Override]
    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.support_entitlements');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return SupportEntitlementForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return SupportEntitlementsTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListSupportEntitlements::route('/'),
            'create' => CreateSupportEntitlement::route('/create'),
            'edit' => EditSupportEntitlement::route('/{record}/edit'),
        ];
    }
}
