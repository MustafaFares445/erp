<?php

declare(strict_types=1);

namespace App\Filament\Resources\FiscalPeriods;

use App\Filament\Resources\FiscalPeriods\Pages\CreateFiscalPeriod;
use App\Filament\Resources\FiscalPeriods\Pages\EditFiscalPeriod;
use App\Filament\Resources\FiscalPeriods\Pages\ListFiscalPeriods;
use App\Filament\Resources\FiscalPeriods\Pages\ViewFiscalPeriod;
use App\Filament\Resources\FiscalPeriods\Schemas\FiscalPeriodForm;
use App\Filament\Resources\FiscalPeriods\Schemas\FiscalPeriodInfolist;
use App\Filament\Resources\FiscalPeriods\Tables\FiscalPeriodsTable;
use App\Models\FiscalPeriod;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * List, Create, Edit, View. Closing and reopening are actions on the table
 * and view page, not form fields, since both are audited service operations
 * rather than edits (FR-016). The View page's Close checklist section is
 * WP-2.5's (GAP-MW-18) reconciliation pack — the evidence a close decision
 * rests on.
 *
 * @see /specs/018-chart-of-accounts-journals/plan.md §Project Structure
 * @see /ERP_REMEDIATION_PLAN.md WP-2.5
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
    public static function infolist(Schema $schema): Schema
    {
        return FiscalPeriodInfolist::configure($schema);
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
            'view' => ViewFiscalPeriod::route('/{record}'),
            'edit' => EditFiscalPeriod::route('/{record}/edit'),
        ];
    }
}
