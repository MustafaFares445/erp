<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees;

use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Employees\Schemas\EmployeeForm;
use App\Filament\Resources\Employees\Schemas\EmployeeInfolist;
use App\Filament\Resources\Employees\Tables\EmployeesTable;
use App\Models\EmployeeProfile;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

final class EmployeeResource extends Resource
{
    protected static ?string $model = EmployeeProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.employees';

    protected static ?int $navigationSort = 601;

    protected static ?string $recordTitleAttribute = 'employee_code';

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.employees');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return EmployeeForm::configure($schema);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return EmployeeInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return EmployeesTable::configure($table);
    }

    /** @return array<string> */
    #[\Override]
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'employee_code',
            'job_title',
            'phone',
            'email',
            'user.name',
            'user.username',
            'user.email',
        ];
    }

    #[\Override]
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        if (! $record instanceof EmployeeProfile) {
            return [];
        }

        return [
            'Employee' => $record->user->name ?? 'Unknown user',
            'Job title' => $record->job_title,
            'Code' => $record->employee_code,
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListEmployees::route('/'),
            'create' => CreateEmployee::route('/create'),
            'view' => ViewEmployee::route('/{record}'),
            'edit' => EditEmployee::route('/{record}/edit'),
        ];
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('user:id,name,username,email')
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
