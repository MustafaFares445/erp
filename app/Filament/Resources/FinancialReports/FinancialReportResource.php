<?php

declare(strict_types=1);

namespace App\Filament\Resources\FinancialReports;

use App\Enums\AccountingPermission;
use App\Filament\Resources\FinancialReports\Pages\ViewFinancialReports;
use App\Filament\Resources\PurchasingReports\PurchasingReportResource;
use App\Models\JournalEntry;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Mirrors {@see PurchasingReportResource}
 * — a single report-viewing page, no CRUD. `JournalEntry` is the nominal model
 * Filament requires; the page renders aggregates, not a record list.
 *
 * Registered under the shared `reports` navigation group, not the `accounting`
 * group (decision D3, FR-048) — the duplicate registration this class used to
 * resolve to is navigation defect N-1.
 */
final class FinancialReportResource extends Resource
{
    protected static ?string $model = JournalEntry::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.financial_reports');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    // @codeCoverageIgnoreStart
    // Required by the abstract Resource contract, but ViewFinancialReports is a
    // plain custom Page, so Filament never calls this.
    #[\Override]
    public static function table(Table $table): Table
    {
        return $table;
    }

    // @codeCoverageIgnoreEnd

    #[\Override]
    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->can(AccountingPermission::ReportView->value);
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
        return ['index' => ViewFinancialReports::route('/')];
    }
}
