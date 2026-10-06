<?php

declare(strict_types=1);

namespace App\Filament\Resources\Visits\Pages;

use App\Filament\Resources\Visits\VisitResource;
use App\Services\Calendar\VisitCalendarEventService;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

final class VisitsCalendar extends Page
{
    protected static string $resource = VisitResource::class;

    protected string $view = 'filament.pages.operational-calendar';

    #[Url]
    public ?string $date = null;

    #[Url]
    public string $mode = 'month';

    public function mount(): void
    {
        $this->date ??= now()->toDateString();

        if (! in_array($this->mode, ['month', 'week', 'day'], true)) {
            $this->mode = 'month';
        }
    }

    #[\Override]
    public function getTitle(): string
    {
        return __('Visits calendar');
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
        $anchor = Carbon::parse($this->date ?? now()->toDateString())->startOfDay();
        [$start, $end, $days, $title, $previous, $next] = $this->range($anchor);

        return [
            'calendarTitle' => $title,
            'anchorDate' => $anchor->toDateString(),
            'mode' => $this->mode,
            'previousDate' => $previous->toDateString(),
            'nextDate' => $next->toDateString(),
            'days' => $days,
            'events' => app(VisitCalendarEventService::class)->between($start, $end),
        ];
    }

    /** @return array{0: Carbon, 1: Carbon, 2: Collection<int, Carbon>, 3: string, 4: Carbon, 5: Carbon} */
    private function range(Carbon $anchor): array
    {
        if ($this->mode === 'day') {
            return [
                $anchor->copy()->startOfDay(),
                $anchor->copy()->endOfDay(),
                collect([$anchor->copy()]),
                $anchor->translatedFormat('l, F j, Y'),
                $anchor->copy()->subDay(),
                $anchor->copy()->addDay(),
            ];
        }

        if ($this->mode === 'week') {
            $start = $anchor->copy()->startOfWeek(Carbon::MONDAY);
            $end = $anchor->copy()->endOfWeek(Carbon::SUNDAY);

            return [
                $start,
                $end,
                collect(range(0, 6))->map(static fn (int $offset): Carbon => $start->copy()->addDays($offset)),
                $start->translatedFormat('M j').' – '.$end->translatedFormat('M j, Y'),
                $anchor->copy()->subWeek(),
                $anchor->copy()->addWeek(),
            ];
        }

        $monthStart = $anchor->copy()->startOfMonth();
        $monthEnd = $anchor->copy()->endOfMonth();
        $gridStart = $monthStart->copy()->startOfWeek(Carbon::MONDAY);

        return [
            $monthStart,
            $monthEnd,
            collect(range(0, 41))->map(static fn (int $offset): Carbon => $gridStart->copy()->addDays($offset)),
            $anchor->translatedFormat('F Y'),
            $anchor->copy()->subMonthNoOverflow(),
            $anchor->copy()->addMonthNoOverflow(),
        ];
    }
}
