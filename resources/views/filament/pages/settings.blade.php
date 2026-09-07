<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @forelse ($this->cards() as $card)
            <a href="{{ $card['url'] }}" class="block rounded-xl border border-gray-200 p-4 transition hover:border-primary-400 hover:shadow-sm dark:border-white/10">
                <div class="flex items-center gap-3">
                    <x-filament::icon :icon="$card['icon']" class="h-6 w-6 text-gray-500 dark:text-gray-400" />
                    <span class="font-semibold">{{ $card['label'] }}</span>
                </div>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $card['description'] }}</p>
            </a>
        @empty
            <p class="text-gray-400">No settings screens are available to your account.</p>
        @endforelse
    </div>
</x-filament-panels::page>
