<x-filament-panels::page>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
            <a href="{{ request()->url() }}?month={{ $previousMonth }}" class="text-sm font-medium text-primary-600">← {{ __('Previous') }}</a>
            <div class="text-lg font-semibold text-gray-950 dark:text-white">{{ $calendarTitle }}</div>
            <a href="{{ request()->url() }}?month={{ $nextMonth }}" class="text-sm font-medium text-primary-600">{{ __('Next') }} →</a>
        </div>

        <div class="grid grid-cols-7 gap-px overflow-hidden rounded-xl bg-gray-200 ring-1 ring-gray-950/5 dark:bg-white/10 dark:ring-white/10">
            @foreach ([__('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat'), __('Sun')] as $weekday)
                <div class="bg-gray-50 px-2 py-2 text-center text-xs font-semibold uppercase text-gray-500 dark:bg-gray-900 dark:text-gray-400">{{ $weekday }}</div>
            @endforeach

            @foreach ($days as $day)
                @php
                    $dateKey = $day->toDateString();
                    $dayEvents = $events->get($dateKey, collect());
                    $inMonth = $day->format('Y-m') === $monthKey;
                @endphp
                <div @class([
                    'min-h-36 bg-white p-2 dark:bg-gray-950',
                    'opacity-45' => ! $inMonth,
                ])>
                    <div @class([
                        'mb-2 flex size-7 items-center justify-center rounded-full text-sm font-semibold',
                        'bg-primary-600 text-white' => $day->isToday(),
                        'text-gray-700 dark:text-gray-200' => ! $day->isToday(),
                    ])>{{ $day->day }}</div>

                    <div class="space-y-1.5">
                        @foreach ($dayEvents as $event)
                            @if ($event['url'] ?? null)
                                <a href="{{ $event['url'] }}" class="block rounded-lg bg-gray-50 px-2 py-1.5 text-xs ring-1 ring-gray-950/5 hover:bg-primary-50 dark:bg-white/5 dark:ring-white/10 dark:hover:bg-primary-950/30">
                            @else
                                <div class="block rounded-lg bg-gray-50 px-2 py-1.5 text-xs ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                            @endif
                                <div class="font-semibold text-gray-950 dark:text-white">{{ $event['time'] ?? null }} {{ $event['title'] }}</div>
                                @if ($event['subtitle'] ?? null)<div class="truncate text-gray-500 dark:text-gray-400">{{ $event['subtitle'] }}</div>@endif
                                <div class="mt-1 text-[10px] uppercase tracking-wide text-gray-400">{{ $event['status'] }}</div>
                            @if ($event['url'] ?? null)</a>@else</div>@endif
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
