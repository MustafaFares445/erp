<?php

declare(strict_types=1);

namespace App\Filament\Resources\CrmReports;

use App\Enums\CrmPermission;
use App\Filament\Resources\CrmReports\Pages\ViewCrmReports;
use App\Models\Lead;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class CrmReportResource extends Resource
{
    protected static ?string $model = Lead::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;
    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.crm';
    protected static ?int $navigationSort = 505;

    #[\Override]
    public static function getNavigationLabel(): string { return 'CRM reports'; }
    #[\Override]
    public static function canAccess(): bool
    {
        $actor = auth()->user();
        return $actor instanceof User && $actor->can(CrmPermission::FunnelReport->value);
    }
    #[\Override]
    public static function canViewAny(): bool { return self::canAccess(); }
    #[\Override]
    public static function canCreate(): bool { return false; }
    #[\Override]
    public static function form(Schema $schema): Schema { return $schema->components([]); }
    #[\Override]
    public static function table(Table $table): Table { return $table; }
    #[\Override]
    public static function getPages(): array { return ['index' => ViewCrmReports::route('/')]; }
}
