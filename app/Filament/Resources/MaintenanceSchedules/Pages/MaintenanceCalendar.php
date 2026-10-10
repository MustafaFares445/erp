<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceSchedules\Pages;

use App\Filament\Concerns\BuildsOperationalCalendar;
use App\Filament\Resources\MaintenanceSchedules\MaintenanceScheduleResource;
use App\Services\Calendar\MaintenanceCalendarEventService;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class MaintenanceCalendar extends Page
{
    use BuildsOperationalCalendar;

    protected static string $resource = MaintenanceScheduleResource::class;

    protected string $view = 'filament.pages.operational-calendar';

    #[\Override]
    public function getTitle(): string
    {
        return __('Maintenance calendar');
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [Action::make('list')->label(__('List view'))->url(MaintenanceScheduleResource::getUrl('index'))];
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function getViewData(): array
    {
        return $this->operationalCalendarViewData(
            static fn (Carbon $start, Carbon $end): Collection => app(MaintenanceCalendarEventService::class)->between($start, $end),
            __('No maintenance due on this day.'),
        );
    }
}
