<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\BuildsOperationalCalendar;
use App\Filament\LocalizedResource;
use App\Services\Calendar\ResourceCalendarEventService;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only calendar companion to an existing Filament resource table.
 * Each resource supplies an audited calendar configuration key.
 */
abstract class ResourceCalendarPage extends Page
{
    use BuildsOperationalCalendar;

    protected string $view = 'filament.pages.operational-calendar';

    protected static string $calendarKey;

    #[\Override]
    public function getTitle(): string
    {
        /** @var class-string<LocalizedResource> $resource */
        $resource = static::getResource();
        $calendar = __('Calendar');

        return (is_string($calendar) ? $calendar : 'Calendar').' — '.$resource::getNavigationLabel();
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        /** @var class-string<LocalizedResource> $resource */
        $resource = static::getResource();

        return [
            Action::make('list')
                ->label(__('List view'))
                ->icon('heroicon-o-list-bullet')
                ->url($resource::getUrl('index')),
        ];
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function getViewData(): array
    {
        return $this->operationalCalendarViewData(
            fn (Carbon $start, Carbon $end): Collection => app(ResourceCalendarEventService::class)
                ->between(static::$calendarKey, $start, $end),
            __('No scheduled records for this day.'),
        );
    }
}
