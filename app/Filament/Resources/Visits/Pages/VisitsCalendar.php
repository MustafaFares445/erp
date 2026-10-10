<?php

declare(strict_types=1);

namespace App\Filament\Resources\Visits\Pages;

use App\Filament\Concerns\BuildsOperationalCalendar;
use App\Filament\Resources\Visits\VisitResource;
use App\Services\Calendar\VisitCalendarEventService;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class VisitsCalendar extends Page
{
    use BuildsOperationalCalendar;

    protected static string $resource = VisitResource::class;

    protected string $view = 'filament.pages.operational-calendar';

    #[\Override]
    public function getTitle(): string
    {
        return __('employees.visits_calendar');
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [Action::make('list')->label(__('employees.list_view'))->url(VisitResource::getUrl('index'))];
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function getViewData(): array
    {
        return $this->operationalCalendarViewData(
            static fn (Carbon $start, Carbon $end): Collection => app(VisitCalendarEventService::class)->between($start, $end),
            __('No visits scheduled for this day.'),
        );
    }
}
