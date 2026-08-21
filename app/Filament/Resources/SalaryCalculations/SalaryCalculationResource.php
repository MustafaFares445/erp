<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalaryCalculations;

use App\Filament\Resources\SalaryCalculations\Pages\ListSalaryCalculations;
use App\Filament\Resources\SalaryCalculations\Pages\ViewSalaryCalculation;
use App\Filament\Resources\SalaryCalculations\RelationManagers\BonusSuggestionsRelationManager;
use App\Filament\Resources\SalaryCalculations\Schemas\SalaryCalculationInfolist;
use App\Filament\Resources\SalaryCalculations\Tables\SalaryCalculationsTable;
use App\Models\EmployeeSalaryCalculation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class SalaryCalculationResource extends Resource
{
    protected static ?string $model = EmployeeSalaryCalculation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.employees';

    protected static ?int $navigationSort = 642;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.salary_calculations');
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return SalaryCalculationInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return SalaryCalculationsTable::configure($table);
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            BonusSuggestionsRelationManager::class,
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListSalaryCalculations::route('/'),
            'view' => ViewSalaryCalculation::route('/{record}'),
        ];
    }
}
