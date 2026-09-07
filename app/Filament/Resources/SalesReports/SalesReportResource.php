<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesReports;

use App\Enums\SalesPermission;
use App\Enums\SalesReportType;
use App\Filament\Resources\CrmReports\CrmReportResource;
use App\Filament\Resources\FinancialReports\FinancialReportResource;
use App\Filament\Resources\SalesReports\Pages\ViewSalesReports;
use App\Models\Invoice;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The sales reporting surface (WP-2.8, GAP-MW-17, GAP-UI-05, SL-15).
 *
 * Read-only, like {@see CrmReportResource}
 * and {@see FinancialReportResource}
 * — no form, no create/edit/delete action, one index page hosting all nine
 * {@see SalesReportType} cases.
 */
final class SalesReportResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.sales';

    protected static ?int $navigationSort = 190;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return 'Sales reports';
    }

    #[\Override]
    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->can(SalesPermission::ReportView->value);
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
    public static function getPages(): array
    {
        return ['index' => ViewSalesReports::route('/')];
    }
}
