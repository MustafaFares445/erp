<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmployeeReports;

use App\Filament\Resources\EmployeeReports\Pages\ManageEmployeeReports;
use App\Models\SalesPlan;
use App\Models\User;
use App\Services\Employees\EmployeeReportService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

final class EmployeeReportResource extends Resource
{
    protected static ?string $model = SalesPlan::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.employee_reports');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table;
    }

    #[\Override]
    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User
            && app(EmployeeReportService::class)->availableReports($actor) !== [];
    }

    #[\Override]
    public static function canViewAny(): bool
    {
        return self::canAccess();
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManageEmployeeReports::route('/')];
    }
}
