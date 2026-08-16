<?php

declare(strict_types=1);

namespace App\Filament\Resources\SlaPolicies;

use App\Filament\Resources\DashboardUsers\DashboardUserResource;
use App\Filament\Resources\SlaPolicies\Pages\EditSlaPolicy;
use App\Filament\Resources\SlaPolicies\Pages\ListSlaPolicies;
use App\Filament\Resources\SlaPolicies\Schemas\SlaPolicyForm;
use App\Filament\Resources\SlaPolicies\Tables\SlaPoliciesTable;
use App\Models\SlaPolicy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * List + Edit only — 4 fixed, seeded rows (data-model.md §5); no Create or
 * Delete route exists at any layer, mirroring
 * {@see DashboardUserResource}.
 */
final class SlaPolicyResource extends Resource
{
    protected static ?string $model = SlaPolicy::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.support';

    protected static ?int $navigationSort = 704;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.sla_policies');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return SlaPolicyForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return SlaPoliciesTable::configure($table);
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[\Override]
    public static function canDeleteAny(): bool
    {
        return false;
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListSlaPolicies::route('/'),
            'edit' => EditSlaPolicy::route('/{record}/edit'),
        ];
    }
}
