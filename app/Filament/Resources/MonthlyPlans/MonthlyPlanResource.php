<?php

declare(strict_types=1);

namespace App\Filament\Resources\MonthlyPlans;

use App\Filament\Resources\MonthlyPlans\Pages\CreateMonthlyPlan;
use App\Filament\Resources\MonthlyPlans\Pages\EditMonthlyPlan;
use App\Filament\Resources\MonthlyPlans\Pages\ListMonthlyPlans;
use App\Filament\Resources\MonthlyPlans\Pages\ViewMonthlyPlan;
use App\Filament\Resources\MonthlyPlans\RelationManagers\TasksRelationManager;
use App\Filament\Resources\MonthlyPlans\Schemas\MonthlyPlanForm;
use App\Filament\Resources\MonthlyPlans\Schemas\MonthlyPlanInfolist;
use App\Filament\Resources\MonthlyPlans\Tables\MonthlyPlansTable;
use App\Models\SalesPlan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

final class MonthlyPlanResource extends Resource
{
    protected static ?string $model = SalesPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.employees';

    protected static ?int $navigationSort = 611;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.monthly_plans');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return MonthlyPlanForm::configure($schema);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return MonthlyPlanInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return MonthlyPlansTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListMonthlyPlans::route('/'),
            'create' => CreateMonthlyPlan::route('/create'),
            'view' => ViewMonthlyPlan::route('/{record}'),
            'edit' => EditMonthlyPlan::route('/{record}/edit'),
        ];
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            TasksRelationManager::class,
        ];
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('employee:id,employee_code,job_title')
            ->withCount('tasks')
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
