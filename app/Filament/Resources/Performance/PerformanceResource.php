<?php

declare(strict_types=1);

namespace App\Filament\Resources\Performance;

use App\Filament\Resources\Performance\Pages\ListPerformanceScores;
use App\Filament\Resources\Performance\Pages\ViewPerformanceScore;
use App\Filament\Resources\Performance\Schemas\PerformanceInfolist;
use App\Filament\Resources\Performance\Tables\PerformanceTable;
use App\Models\EmployeePerformanceScore;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class PerformanceResource extends Resource
{
    protected static ?string $model = EmployeePerformanceScore::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.employees';

    protected static ?int $navigationSort = 641;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.performance');
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return PerformanceInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return PerformanceTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListPerformanceScores::route('/'),
            'view' => ViewPerformanceScore::route('/{record}'),
        ];
    }
}
