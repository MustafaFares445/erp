<x-filament-panels::page>
    <div class="space-y-4">
        <div class="ierp-card space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <a href="{{ request()->url() }}?mode={{ $mode }}&date={{ $previousDate }}" class="text-sm font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400">← {{ __('Previous') }}</a>
                <div class="text-lg font-semibold text-gray-950 dark:text-white">{{ $calendarTitle }}</div>
                <a href="{{ request()->url() }}?mode={{ $mode }}&date={{ $nextDate }}" class="text-sm font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400">{{ __('Next') }} →</a>
            </div>

            <div class="flex flex-wrap items-center justify-center gap-2">
                @foreach (['month' => __('Month'), 'week' => __('Week'), 'day' => __('Day')] as $calendarMode => $label)
                    <a
                        href="{{ request()->url() }}?mode={{ $calendarMode }}&date={{ $anchorDate }}"
                        @class([
                            'rounded-lg px-3 py-1.5 text-sm font-medium ring-1 transition',
                            'bg-primary-600 text-white ring-primary-600' => $mode === $calendarMode,
                            'bg-white text-gray-700 ring-gray-200 hover:bg-gray-50 dark:bg-white/5 dark:text-gray-200 dark:ring-white/10' => $mode !== $calendarMode,
                        ])
                    >
                        {{ $label }}
                    </a>
                @endforeach
                <a href="{{ request()->url() }}?mode={{ $mode }}&date={{ now()->toDateString() }}" class="rounded-lg px-3 py-1.5 text-sm font-medium text-primary-600 ring-1 ring-gray-200 hover:bg-primary-50 dark:ring-white/10">{{ __('Today') }}</a>
            </div>
        </div>

        <div @class([
            'grid gap-px overflow-hidden rounded-xl bg-gray-200 ring-1 ring-gray-200 dark:bg-white/10 dark:ring-white/10',
            'grid-cols-1' => $mode === 'day',
            'grid-cols-7' => $mode !== 'day',
        ])>
            @if ($mode !== 'day')
                @foreach ([__('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat'), __('Sun')] as $weekday)
                    <div class="bg-gray-50 px-2 py-2 text-center text-xs font-semibold uppercase text-gray-500 dark:bg-gray-900 dark:text-gray-400">{{ $weekday }}</div>
                @endforeach
            @endif

            @foreach ($days as $day)
                @php
                    $dateKey = $day->toDateString();
                    $dayEvents = $events->get($dateKey, collect());
                    $outsideMonth = $mode === 'month' && $day->format('Y-m') !== substr($anchorDate, 0, 7);
                @endphp
                <div @class([
                    'bg-white p-3 dark:bg-gray-900',
                    'min-h-36' => $mode !== 'day',
                    'min-h-80' => $mode === 'day',
                    'opacity-45' => $outsideMonth,
                ])>
                    <div class="mb-3 flex items-center justify-between gap-2">
                        <div @class([
                            'flex size-8 items-center justify-center rounded-full text-sm font-semibold',
                            'bg-primary-600 text-white' => $day->isToday(),
                            'text-gray-700 dark:text-gray-200' => ! $day->isToday(),
                        ])>{{ $day->day }}</div>
                        <div class="text-xs font-medium text-gray-500">{{ $day->translatedFormat('D') }}</div>
                    </div>

                    <div class="space-y-2">
                        @forelse ($dayEvents as $event)
                            <a href="{{ $event['url'] }}" class="block rounded-lg bg-gray-50 px-2.5 py-2 text-xs ring-1 ring-gray-950/5 transition hover:bg-primary-50 dark:bg-white/5 dark:ring-white/10 dark:hover:bg-primary-950/30">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="font-semibold text-gray-950 dark:text-white">{{ $event['time'] ?? null }} {{ $event['title'] }}</div>
                                    <span class="rounded-md bg-white px-1.5 py-0.5 text-[10px] font-semibold text-gray-600 ring-1 ring-gray-200 dark:bg-white/10 dark:text-gray-300 dark:ring-white/10">{{ $event['status'] }}</span>
                                </div>
                                @if ($event['subtitle'] ?? null)
                                    <div class="mt-1 truncate text-gray-500 dark:text-gray-400">{{ $event['subtitle'] }}</div>
                                @endif
                            </a>
                        @empty
                            @if ($mode === 'day')
                                <div class="rounded-lg border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500 dark:border-white/15 dark:text-gray-400">
                                    {{ __('No visits scheduled for this day.') }}
                                </div>
                            @endif
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
