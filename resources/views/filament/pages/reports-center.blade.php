<x-filament-panels::page>
    @php($domains = $this->domains())

    <div class="space-y-6">
        <div class="ierp-card">
            <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-end">
                <div>
                    <p class="ierp-eyebrow">{{ __('reporting.center.eyebrow') }}</p>
                    <h2 class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">
                        {{ __('reporting.center.heading') }}
                    </h2>
                    <p class="mt-2 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
                        {{ __('reporting.center.description') }}
                    </p>
                </div>

                <div>
                    <label class="ierp-label" for="reports-center-search">{{ __('reporting.center.search_label') }}</label>
                    <x-filament::input.wrapper class="mt-2" prefix-icon="heroicon-m-magnifying-glass">
                        <x-filament::input
                            id="reports-center-search"
                            type="search"
                            wire:model.live.debounce.250ms="search"
                            :placeholder="__('reporting.center.search_placeholder')"
                        />
                    </x-filament::input.wrapper>
                </div>
            </div>
        </div>

        <div wire:loading.delay class="w-full">
            <div class="ierp-card flex items-center gap-3 text-sm text-gray-600 dark:text-gray-300">
                <x-filament::loading-indicator class="h-5 w-5" />
                <span>{{ __('reporting.states.loading') }}</span>
            </div>
        </div>

        <div wire:loading.remove>
            @forelse ($domains as $domain)
                <x-filament::section class="mb-6">
                    <x-slot name="heading">
                        <span class="flex items-center gap-2">
                            <x-filament::icon :icon="$domain['icon']" class="h-5 w-5 text-primary-600 dark:text-primary-400" />
                            {{ $domain['label'] }}
                        </span>
                    </x-slot>
                    <x-slot name="description">{{ $domain['description'] }}</x-slot>

                    @php($byCategory = collect($domain['reports'])->groupBy(fn ($report) => $report->category))

                    <div class="grid gap-5 xl:grid-cols-2">
                        @foreach ($byCategory as $category => $reports)
                            <div class="ierp-card border border-gray-200/80 p-0 dark:border-white/10">
                                <div class="border-b border-gray-200/80 px-4 py-3 dark:border-white/10">
                                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $category }}</h3>
                                </div>

                                <div class="divide-y divide-gray-200/80 dark:divide-white/10">
                                    @foreach ($reports as $report)
                                        @if ($url = $report->url())
                                            <a
                                                href="{{ $url }}"
                                                class="group flex items-start justify-between gap-4 px-4 py-3 transition hover:bg-gray-50 dark:hover:bg-white/5"
                                            >
                                                <span class="min-w-0">
                                                    <span class="block text-sm font-medium text-gray-950 group-hover:text-primary-600 dark:text-white dark:group-hover:text-primary-400">
                                                        {{ $report->label }}
                                                    </span>
                                                    <span class="mt-1 block text-xs leading-5 text-gray-500 dark:text-gray-400">
                                                        {{ $report->description }}
                                                    </span>
                                                </span>
                                                <x-filament::icon
                                                    icon="heroicon-m-chevron-right"
                                                    class="mt-1 h-4 w-4 shrink-0 text-gray-400 rtl:rotate-180"
                                                />
                                            </a>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @empty
                <div class="ierp-card py-12 text-center">
                    <x-filament::icon icon="heroicon-o-document-magnifying-glass" class="mx-auto h-10 w-10 text-gray-400" />
                    <h3 class="mt-3 text-sm font-semibold text-gray-950 dark:text-white">{{ __('reporting.states.no_matching_reports') }}</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('reporting.states.try_different_search') }}</p>
                    @if ($search !== '')
                        <x-filament::button class="mt-4" color="gray" wire:click="$set('search', '')">
                            {{ __('reporting.actions.clear_search') }}
                        </x-filament::button>
                    @endif
                </div>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
