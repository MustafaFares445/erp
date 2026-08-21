<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportReports;

use App\Filament\Resources\EmployeeReports\EmployeeReportResource;
use App\Filament\Resources\SupportReports\Pages\ViewSupportReports;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\SupportReportService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Mirrors {@see EmployeeReportResource}
 * — a single report-viewing page, no CRUD. `Ticket` is used only as the
 * resource's nominal model (Filament requires one); the page itself renders
 * aggregate data, not a record list.
 */
final class SupportReportResource extends Resource
{
    protected static ?string $model = Ticket::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.support_reports');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    // @codeCoverageIgnoreStart
    // Required by the abstract Resource contract, but ViewSupportReports is a plain
    // custom Page (not a List/ManageRecords page), so Filament never actually calls this.
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

        return $actor instanceof User && app(SupportReportService::class)->canView($actor);
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
        return ['index' => ViewSupportReports::route('/')];
    }
}
