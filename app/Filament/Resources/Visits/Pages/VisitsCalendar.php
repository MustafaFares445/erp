<?php

declare(strict_types=1);

namespace App\Filament\Resources\Visits\Pages;

use App\Filament\Resources\Visits\VisitResource;
use App\Services\Calendar\VisitCalendarEventService;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

final class VisitsCalendar extends Page
{
    protected static string $resource = VisitResource::class;

    protected string $view = 'filament.pages.operational-calendar';

    #[Url]
    public ?string $month = null;

    public function mount(): void
    {
        $this->month ??= now()->format('Y-m');
    }

    #[\Override]
    public function getTitle(): string
    {
        return __('Visits & tasks calendar');
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [Action::make('list')->label(__('List view'))->url(VisitResource::getUrl('index'))];
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function getViewData(): array
    {
        $month = Carbon::createFromFormat('!Y-m', $this->month ?? now()->format('Y-m')) ?: now()->startOfMonth();
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $gridStart = $start->copy()->startOfWeek(Carbon::MONDAY);
        $days = collect(range(0, 41))->map(fn (int $offset): Carbon => $gridStart->copy()->addDays($offset));

        return [
            'calendarTitle' => $month->translatedFormat('F Y'),
            'monthKey' => $month->format('Y-m'),
            'previousMonth' => $month->copy()->subMonth()->format('Y-m'),
            'nextMonth' => $month->copy()->addMonth()->format('Y-m'),
            'days' => $days,
            'events' => app(VisitCalendarEventService::class)->between($start, $end),
        ];
    }
}
