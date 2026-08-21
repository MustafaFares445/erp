<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchasingReports;

use App\Enums\PurchasePermission;
use App\Filament\Resources\PurchasingReports\Pages\ListPurchasingReports;
use App\Filament\Resources\SupportReports\SupportReportResource;
use App\Models\PurchaseOrder;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Mirrors {@see SupportReportResource} — a single report-viewing page, no CRUD.
 *
 * Registered under the shared `reports` navigation group rather than inside the
 * purchasing group (R-011): `AdminModuleRegistry` already establishes that every
 * module's reports live together, and the purchasing group holds four items, not
 * the fifteen that would justify sections of its own.
 *
 * `PurchaseOrder` is the nominal model Filament requires; the page renders
 * aggregates, not a record list.
 */
final class PurchasingReportResource extends Resource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.purchasing_reports');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    // @codeCoverageIgnoreStart
    // Required by the abstract Resource contract, but ListPurchasingReports is a
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

        return $actor instanceof User && $actor->can(PurchasePermission::ReportView->value);
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
        return ['index' => ListPurchasingReports::route('/')];
    }
}
