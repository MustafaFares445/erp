<x-filament-panels::page>
    <p class="text-sm text-gray-500 dark:text-gray-400">
        {{ $description }}
    </p>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        @foreach ($groups as $group)
            <x-filament::section
                :heading="$group['label']"
                :icon="$group['icon']"
                compact
            >
                @if ($group['items']->isEmpty())
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('admin.empty_module') }}
                    </p>
                @else
                    <ul class="flex flex-col gap-y-2">
                        @foreach ($group['items'] as $item)
                            <li>
                                <x-filament::link :href="$item['url']">
                                    {{ $item['label'] }}
                                </x-filament::link>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
