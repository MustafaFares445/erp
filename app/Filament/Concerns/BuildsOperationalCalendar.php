<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Month / week / day navigation shared by the pages rendered with the
 * `filament.pages.operational-calendar` view.
 */
trait BuildsOperationalCalendar
{
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

    /**
     * @param  Closure(Carbon, Carbon): Collection<int|string, covariant mixed>  $eventsBetween
     * @return array<string, mixed>
     */
    protected function operationalCalendarViewData(Closure $eventsBetween, string $emptyDayMessage): array
    {
        try {
            $anchor = Carbon::parse($this->date ?? now()->toDateString())->startOfDay();
        } catch (\Throwable) {
            $anchor = now()->startOfDay();
        }

        [$start, $end, $days, $title, $previous, $next] = $this->range($anchor);
        $weekStart = $anchor->copy()->startOfWeek(Carbon::MONDAY);

        return [
            'calendarTitle' => $title,
            'anchorDate' => $anchor->toDateString(),
            'mode' => $this->mode,
            'previousDate' => $previous->toDateString(),
            'nextDate' => $next->toDateString(),
            'days' => $days,
            'weekdays' => collect(range(0, 6))->map(static fn (int $offset): string => $weekStart->copy()->addDays($offset)->translatedFormat('D'))->all(),
            // Include the adjacent-month days shown in the six-week month grid.
            'events' => $eventsBetween($days->first()->copy()->startOfDay(), $days->last()->copy()->endOfDay()),
            'emptyDayMessage' => $emptyDayMessage,
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
