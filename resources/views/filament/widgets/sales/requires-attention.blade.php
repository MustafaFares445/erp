<x-filament-widgets::widget>
    <x-filament::section heading="Requires attention">
        @if (empty($items))
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Nothing needs attention right now.
            </p>
        @else
            <ul class="flex flex-col divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($items as $item)
                    <li class="flex items-center justify-between gap-x-4 py-3 first:pt-0 last:pb-0">
                        <div class="flex items-center gap-x-3">
                            <x-filament::badge :color="$item['color']">
                                {{ $item['count'] }}
                            </x-filament::badge>

                            <div>
                                <p class="text-sm font-medium text-gray-950 dark:text-white">
                                    {{ $item['label'] }}
                                </p>
                                @if ($item['detail'])
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ $item['detail'] }}
                                    </p>
                                @endif
                            </div>
                        </div>

                        @if ($item['url'])
                            <x-filament::button tag="a" :href="$item['url']" color="gray" size="sm" outlined>
                                View
                            </x-filament::button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
