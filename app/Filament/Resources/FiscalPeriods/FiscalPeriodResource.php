<?php

declare(strict_types=1);

namespace App\Filament\Resources\FiscalPeriods;

use App\Filament\Resources\FiscalPeriods\Pages\CreateFiscalPeriod;
use App\Filament\Resources\FiscalPeriods\Pages\EditFiscalPeriod;
use App\Filament\Resources\FiscalPeriods\Pages\ListFiscalPeriods;
use App\Filament\Resources\FiscalPeriods\Schemas\FiscalPeriodForm;
use App\Filament\Resources\FiscalPeriods\Tables\FiscalPeriodsTable;
use App\Models\FiscalPeriod;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * List, Create, Edit — no View page, because a period has nothing to show beyond
 * the fields already on its form. Closing and reopening are actions on the table
 * and edit page, not form fields, since both are audited service operations
 * rather than edits (FR-016).
 *
 * @see /specs/018-chart-of-accounts-journals/plan.md §Project Structure
 */
final class FiscalPeriodResource extends Resource
{
    protected static ?string $model = FiscalPeriod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.accounting';

    protected static ?int $navigationSort = 203;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.fiscal_periods');
    }

    #[\Override]
    public static function getModelLabel(): string
    {
        return __('admin.resources.fiscal_periods');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return FiscalPeriodForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return FiscalPeriodsTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListFiscalPeriods::route('/'),
            'create' => CreateFiscalPeriod::route('/create'),
            'edit' => EditFiscalPeriod::route('/{record}/edit'),
        ];
    }
}
