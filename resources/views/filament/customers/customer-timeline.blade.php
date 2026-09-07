<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Summary</x-slot>
        <x-slot name="description">Lifetime value, outstanding position, and activity at a glance (CR-05).</x-slot>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-5">
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Lifetime invoiced</p>
                <p class="text-xl font-semibold">{{ number_format($summary['lifetime_invoiced_minor'] / 100, 2) }}</p>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Lifetime collected</p>
                <p class="text-xl font-semibold">{{ number_format($summary['lifetime_collected_minor'] / 100, 2) }}</p>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Outstanding</p>
                <p class="text-xl font-semibold">{{ number_format($summary['outstanding']['outstanding_minor'] / 100, 2) }}</p>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Open tickets</p>
                <p class="text-xl font-semibold">{{ $summary['open_tickets'] }}</p>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Last interaction</p>
                <p class="text-xl font-semibold">{{ $summary['last_interaction_at']?->diffForHumans() ?? '—' }}</p>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Filters</x-slot>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
            <x-filament::input.wrapper label="From">
                <x-filament::input type="date" wire:model.live="from" />
            </x-filament::input.wrapper>
            <x-filament::input.wrapper label="Until">
                <x-filament::input type="date" wire:model.live="until" />
            </x-filament::input.wrapper>
            <div class="sm:col-span-2">
                <p class="mb-1 text-sm font-medium text-gray-500 dark:text-gray-400">Types</p>
                <div class="flex flex-wrap gap-3">
                    @foreach ($availableTypes as $type)
                        <label class="flex items-center gap-1 text-sm">
                            <input type="checkbox" wire:model.live="types" value="{{ $type }}" />
                            {{ str($type)->replace('_', ' ')->headline() }}
                        </label>
                    @endforeach
                </div>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Timeline</x-slot>
        <x-slot name="description">Reverse-chronological across every source this account manager may see.</x-slot>

        <ul class="divide-y divide-gray-100 dark:divide-white/10">
            @forelse ($timeline as $event)
                <li class="flex items-center justify-between gap-4 py-3">
                    <div>
                        <p class="font-medium">
                            @if ($event['link'])
                                <a href="{{ $event['link'] }}" class="hover:underline">{{ $event['title'] }}</a>
                            @else
                                {{ $event['title'] }}
                            @endif
                        </p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $event['subtitle'] }}</p>
                    </div>
                    <div class="text-right text-sm text-gray-500 dark:text-gray-400">
                        <p>{{ \Illuminate\Support\Carbon::parse($event['occurred_at'])->format('Y-m-d H:i') }}</p>
                        @if ($event['actor'])
                            <p>{{ $event['actor'] }}</p>
                        @endif
                    </div>
                </li>
            @empty
                <li class="py-3 text-gray-400">Nothing to show in this range.</li>
            @endforelse
        </ul>

        <div class="mt-4">
            {{ $timeline->links() }}
        </div>
    </x-filament::section>
</x-filament-panels::page>
