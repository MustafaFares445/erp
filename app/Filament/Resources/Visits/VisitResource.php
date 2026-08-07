<?php

declare(strict_types=1);

namespace App\Filament\Resources\Visits;

use App\Filament\Resources\Visits\Pages\EditVisit;
use App\Filament\Resources\Visits\Pages\ListVisits;
use App\Filament\Resources\Visits\Pages\ViewVisit;
use App\Filament\Resources\Visits\Schemas\VisitForm;
use App\Filament\Resources\Visits\Schemas\VisitInfolist;
use App\Filament\Resources\Visits\Tables\VisitsTable;
use App\Models\CustomerVisit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class VisitResource extends Resource
{
    protected static ?string $model = CustomerVisit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.employees';

    protected static ?int $navigationSort = 621;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.visits');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return VisitForm::configure($schema);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return VisitInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return VisitsTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListVisits::route('/'),
            'view' => ViewVisit::route('/{record}'),
            'edit' => EditVisit::route('/{record}/edit'),
        ];
    }
}
