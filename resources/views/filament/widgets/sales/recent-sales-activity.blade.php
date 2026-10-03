<x-filament-widgets::widget>
    <x-filament::section :heading="__('dashboards.sales.cards.recent_activity')">
        @if (empty($events))
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('dashboards.sales.empty.sales') }}
            </p>
        @else
            <ul class="flex flex-col divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($events as $event)
                    <li class="flex items-center justify-between gap-x-4 py-2 first:pt-0 last:pb-0">
                        <div>
                            <p class="text-sm font-medium text-gray-950 dark:text-white">
                                @if ($event['url'])
                                    <a href="{{ $event['url'] }}" class="hover:underline">{{ $event['label'] }}</a>
                                @else
                                    {{ $event['label'] }}
                                @endif
                            </p>
                            @if ($event['detail'])
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $event['detail'] }}</p>
                            @endif
                        </div>
                        <span class="whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">
                            {{ $event['timestamp']->diffForHumans() }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
