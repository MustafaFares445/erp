<x-filament-panels::page>
    <div class="space-y-6">
        <div class="max-w-2xl">
            <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                <x-filament::input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search settings…') }}"
                    autocomplete="off"
                />
            </x-filament::input.wrapper>
        </div>

        @forelse (collect($this->cards())->groupBy('group') as $group => $cards)
            <section class="space-y-3">
                <h2 class="ierp-eyebrow">
                    {{ __($group) }}
                </h2>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($cards as $card)
                        <a href="{{ $card['url'] }}" class="ierp-card-link">
                            <div class="flex items-center gap-3">
                                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-500/15 dark:text-primary-400">
                                    <x-filament::icon :icon="$card['icon']" class="size-5" />
                                </span>
                                <span class="font-semibold">{{ $card['label'] }}</span>
                            </div>
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $card['description'] }}</p>
                        </a>
                    @endforeach
                </div>
            </section>
        @empty
            <x-filament::section>
                <div class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                    {{ __('No settings match your search or permissions.') }}
                </div>
            </x-filament::section>
        @endforelse
    </div>
</x-filament-panels::page>
