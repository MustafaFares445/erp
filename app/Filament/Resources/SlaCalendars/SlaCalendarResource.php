<?php

declare(strict_types=1);

namespace App\Filament\Resources\SlaCalendars;

use App\Filament\Concerns\RequiresSupportFeature;
use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\SlaCalendars\Pages\CreateSlaCalendar;
use App\Filament\Resources\SlaCalendars\Pages\EditSlaCalendar;
use App\Filament\Resources\SlaCalendars\Pages\ListSlaCalendars;
use App\Filament\Resources\SlaCalendars\Schemas\SlaCalendarForm;
use App\Filament\Resources\SlaCalendars\Tables\SlaCalendarsTable;
use App\Models\SlaCalendar;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class SlaCalendarResource extends Resource
{
    use RequiresSupportFeature;

    protected static ?string $model = SlaCalendar::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.support';

    protected static ?int $navigationSort = 705;

    protected static ?string $recordTitleAttribute = 'name';

    #[\Override]
    protected static function supportFeatureFlag(): string
    {
        return 'support.sla_v2_enabled';
    }

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.sla_calendars');
    }

    #[\Override]
    public static function getModelLabel(): string
    {
        return __('admin.resources.sla_calendar');
    }

    #[\Override]
    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.sla_calendars');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return SlaCalendarForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return SlaCalendarsTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListSlaCalendars::route('/'),
            'create' => CreateSlaCalendar::route('/create'),
            'edit' => EditSlaCalendar::route('/{record}/edit'),
        ];
    }
}
